<?php

namespace Cav7\Citations\Pub\Controller;

use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\ManagerAccess;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Phrase;
use XF\PrintableException;
use XF\Pub\Controller\AbstractController;

/**
 * S1's template manager. Every action answers to ManagerAccess before it runs, so a crafted
 * POST meets the same refusal as a click. Refusals from the services and entities are
 * PrintableExceptions, which the dispatcher turns into an error reply holding every message.
 */
abstract class AbstractManager extends AbstractController
{
    /** This controller's page in ManagerAccess. */
    protected const PAGE = '';

    protected function preDispatchController($action, ParameterBag $params): void
    {
        if (!ManagerAccess::allows(static::PAGE, strtolower((string) $action), $this->actor())) {
            throw $this->exception($this->noPermission());
        }
    }

    protected function actor(): Actor
    {
        return $this->repository('Cav7\Citations:Grant')->actor(\XF::visitor());
    }

    /**
     * The upload's temporary path, for ImageIntake to judge. XF's own validity check stays out
     * of it, since it goes by the filename and would answer ahead of the signature bytes.
     *
     * @return string|null Null when no file came.
     * @throws PrintableException
     */
    protected function uploaded(string $name): ?string
    {
        $upload = $this->request->getFile($name, false, false);
        if (!$upload) {
            return null;
        }

        if ($upload->getUploadError()) {
            $upload->isValid($errors);
            throw new PrintableException($errors);
        }
        return $upload->getTempFile();
    }

    /**
     * A plate, ink or rank art file. Links carry the hash, so the URL changes with the file.
     *
     * @param 'png'|'jpeg' $format
     */
    protected function image(string $hash, string $format): AbstractReply
    {
        $path = FileStore::forApp($this->app)->path($hash, $format);
        if (!is_file($path)) {
            return $this->notFound();
        }

        $this->setResponseType('raw');
        $this->app->response()
            ->contentType($format === 'png' ? 'image/png' : 'image/jpeg', '')
            ->header('Cache-Control', 'private, max-age=86400')
            ->header('X-Content-Type-Options', 'nosniff');

        return $this->view('', '', ['innerContent' => file_get_contents($path)]);
    }

    /**
     * The one page that asks before a write. Problems replace the button.
     *
     * @param list<Phrase> $problems
     */
    protected function confirm(Phrase $title, Phrase $message, string $action, Phrase $button, array $problems = []): AbstractReply
    {
        return $this->view('Cav7\Citations:Manager\Confirm', 'cav7_cit_manager_confirm', [
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'button' => $button,
            'problems' => $problems,
        ]);
    }
}
