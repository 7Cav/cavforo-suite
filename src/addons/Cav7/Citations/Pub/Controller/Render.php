<?php

namespace Cav7\Citations\Pub\Controller;

use Cav7\Citations\Entity\Grant;
use Cav7\Citations\Entity\GrantMember;
use Cav7\Citations\Grant\GrantRender;
use Cav7\Citations\Grant\RenderAnswer;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Storage\DamageLog;
use Cav7\Citations\Template\TypesettingRows;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Pub\App;
use XF\Pub\Controller\AbstractController;

/**
 * Citation renders, public by URL. Skipping the pre-dispatch gates is the point: a citation
 * stays readable to banned visitors and on a closed board, the same as a static one.
 *
 * Every 200 and 301 sits in a shared cache for 8 days, so no reply may carry Set-Cookie.
 * Assumes XF handles the session and remember cookie before dispatch, and saves only a
 * session holding data. Re-check with curl after an XF upgrade.
 */
class Render extends AbstractController
{
    private const MAX_AGE = 691200;

    protected function preDispatchType($action, ParameterBag $params): void
    {
        $this->setResponseType('raw');
        App::$allowPageCache = false;

        // A remember cookie makes XF start a session, and expunging a stored one signs the
        // visitor out. A stale remember cookie makes XF clear it, which is a Set-Cookie too.
        $session = $this->session();
        if (!$session->exists()) {
            $session->expunge();
        }

        $response = $this->app->response();
        foreach (array_keys($response->getCookies()) as $cookie) {
            $response->removeCookie($cookie);
        }

        // Raw replies keep XF's unknown/unknown, and only a hit overrides this.
        $response->contentType('text/plain');
    }

    protected function canUpdateSessionActivity($action, ParameterBag $params, AbstractReply &$reply, &$viewState): bool
    {
        return false;
    }

    // Each action reads in one transaction, so the grant row and the typesetting drawn on it
    // come from one InnoDB snapshot and a correction committing between them can't mix revisions.
    public function actionIndex(ParameterBag $params): AbstractReply
    {
        return $this->app->db()->executeTransaction(function () use ($params): AbstractReply {
            /** @var GrantMember|null $member */
            $member = $this->em()->find('Cav7\Citations:GrantMember', $params->grant_member_id, 'Grant.Version');

            return $this->answer($params, $member, $member?->Grant, (int) $member?->grant_member_id);
        });
    }

    public function actionRegiment(ParameterBag $params): AbstractReply
    {
        return $this->app->db()->executeTransaction(function () use ($params): AbstractReply {
            /** @var Grant|null $grant */
            $grant = $this->em()->find('Cav7\Citations:Grant', $params->grant_id, 'Version');

            return $this->answer($params, $grant, $grant, TypesettingRows::SHARED);
        });
    }

    private function answer(ParameterBag $params, GrantMember|Grant|null $citation, ?Grant $grant, int $grantMemberId): AbstractReply
    {
        $answer = RenderAnswer::for(
            $citation?->url_token,
            (string) $params->url_token,
            (int) $grant?->withdrawn_date,
            (int) $grant?->revision,
            (int) $params->revision,
        );

        return match ($answer) {
            RenderAnswer::NotFound => $this->uncached(404),
            RenderAnswer::Redirect => $this->cacheable($this->redirectPermanently($citation->renderUrl(true), '')),
            RenderAnswer::Serve => $this->serve($citation, $grant, $grantMemberId),
        };
    }

    private function serve(GrantMember|Grant $citation, Grant $grant, int $grantMemberId): AbstractReply
    {
        try {
            $version = $grant->Version ?? throw new RenderFailed("Grant {$grant->grant_id} has no template version");
            $jpeg = GrantRender::forApp($this->app)->draw($version, $grant->grant_id, $grantMemberId)->jpeg;
        } catch (RenderFailed | \JsonException | \InvalidArgumentException $e) {
            $damage = new DamageLog(fn (string $line) => \XF::logError($line), fn (string $line) => null);
            $damage->report(
                "render failed: {$e->getMessage()}",
                '',
                $grantMemberId !== TypesettingRows::SHARED ? "grant_member:$grantMemberId" : "grant:{$grant->grant_id}"
            );

            return $this->uncached(500);
        }

        $this->app->response()
            ->contentType('image/jpeg', '')
            ->setDownloadFileName($citation->renderFilename(), true);

        return $this->cacheable($this->view('', '', ['innerContent' => $jpeg]));
    }

    private function cacheable(AbstractReply $reply): AbstractReply
    {
        $this->app->response()
            ->header('Cache-Control', 'public, max-age=' . self::MAX_AGE)
            ->header('Expires', gmdate('D, d M Y H:i:s', \XF::$time + self::MAX_AGE) . ' GMT')
            ->header('X-Robots-Tag', 'noindex');

        return $reply;
    }

    private function uncached(int $code): AbstractReply
    {
        $this->app->response()->header('Cache-Control', 'no-store');

        return $this->message('', $code);
    }
}
