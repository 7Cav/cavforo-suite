<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Render\DrawResult;
use Cav7\Citations\Render\ImagickMetrics;
use Cav7\Citations\Render\LibraryVersions;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\OverrunLog;
use Cav7\Citations\Template\TypesettingRows;
use Cav7\Citations\Template\VersionRender;
use XF\App;

/** Grants joined to the render: making a typesetting, storing it and drawing from it. Share one across a bulk run. */
final class GrantRender
{
    private const ADDON_ID = 'Cav7/Citations';

    public function __construct(
        private VersionRender $render,
        public readonly TypesettingRows $rows,
        private OverrunLog $overruns,
        public readonly LibraryVersions $libraries,
        public readonly int $addonVersionId,
    ) {
    }

    public static function forApp(App $app): self
    {
        return new self(
            new VersionRender(FileStore::forApp($app), new ImagickMetrics()),
            new TypesettingRows($app->db()),
            new OverrunLog($app->db(), fn (string $message) => \XF::logError($message)),
            LibraryVersions::read(),
            (int) $app->container('addon.cache')[self::ADDON_ID],
        );
    }

    /**
     * Imagick work, so run it ahead of the transaction: a missing font then saves nothing and
     * holds no locks.
     *
     * @param array<string, PinnedSignature> $signatures By slot key.
     * @throws RenderFailed
     */
    public function make(TemplateVersion $version, GrantValues $values, RemakePlan $plan, array $signatures): Typesetting
    {
        $design = $version->renderVersion();
        $set = fn (array $fields, ?MemberValues $member): array => $this->render->typeset(
            $version,
            $fields,
            $values->printed(
                $design,
                $member,
                $signatures,
                $version->rankArtFor($values->rankIdFor($design, $member) ?? 0),
            ),
        );

        $members = [];
        foreach ($plan->members as $key => $fields) {
            $members[$key] = $set($fields, $values->members[$key]);
        }
        return new Typesetting($set($plan->shared, null), $members);
    }

    /**
     * Sets and draws a form's values with nothing stored, through the same typesetter an issue
     * runs, so what it warns about is what the issue would flag.
     *
     * @param array<string, PinnedSignature> $signatures By slot key.
     * @throws RenderFailed
     */
    public function preview(TemplateVersion $version, GrantValues $values, ?MemberValues $member, array $signatures): GrantPreview
    {
        $design = $version->renderVersion();
        $rows = PreviewFit::rows($design, $this->render->typeset(
            $version,
            PreviewFit::printable($design, $values->date, $signatures),
            $values->printed($design, $member, $signatures, $version->rankArtFor($values->rankIdFor($design, $member) ?? 0)),
        ));

        return new GrantPreview($this->render->draw($version, $rows)->jpeg, PreviewFit::of($design, $rows, $version->plate_width));
    }

    /** @param array<int, int> $memberIds Grant member id by the key in the typesetting, when those differ. */
    public function store(int $grantId, Typesetting $typesetting, array $memberIds = []): void
    {
        $write = fn (int $memberId, array $fields) => $this->rows->write(
            $grantId,
            $memberId,
            $fields,
            $this->addonVersionId,
            $this->libraries,
            \XF::$time,
        );

        $write(TypesettingRows::SHARED, $typesetting->shared);
        foreach ($typesetting->members as $key => $fields) {
            $write($memberIds[$key] ?? $key, $fields);
        }
    }

    /**
     * @param int $grantMemberId 0 for a regiment grant.
     * @throws RenderFailed
     */
    public function draw(TemplateVersion $version, int $grantId, int $grantMemberId = TypesettingRows::SHARED): DrawResult
    {
        $result = $this->render->draw($version, $this->rows->read($grantId, $grantMemberId));

        if ($result->overrun !== null) {
            $this->overruns->record($version->template_version_id, $grantMemberId, $result->overrun, $this->libraries, \XF::$time);
        }
        return $result;
    }
}
