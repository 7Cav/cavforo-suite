<?php

namespace Cav7\TicketWebhook\Admin\Controller;

use Cav7\TicketWebhook\Entity\Hook as HookEntity;
use Cav7\TicketWebhook\Repository\Hook as HookRepo;
use Cav7\TicketWebhook\Token;
use XF\ControllerPlugin\DeletePlugin;
use XF\ControllerPlugin\TogglePlugin;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/**
 * The hook screens: list, add, edit, delete, toggle active, and replace the
 * token.
 *
 * Guarded by the vendor's `nfTickets` admin permission, so whoever manages
 * ticket categories manages hooks too. The addon ships no admin permission
 * of its own.
 *
 * A token is shown once. Create and replace both generate it, store its hash
 * on the hook, park the token in the admin session, and redirect to the
 * token screen, which reads it out of the session and forgets it. Reloading
 * that screen shows nothing and goes back to the list. The ACP forms submit
 * over AJAX and can only redirect on success, which is why the token takes
 * the session rather than riding in the save's own reply.
 *
 * That parking is the one place the token exists outside the admin's
 * screen: XenForo keeps the admin session in xf_session_admin, so the token
 * sits there for the one redirect between the save and the screen, or until
 * the session expires if the screen is never loaded. The hook itself holds
 * only the hash.
 */
class Hook extends \XF\Admin\Controller\AbstractController
{
    /**
     * The admin session key the token waits under between the save that
     * made it and the screen that shows it.
     */
    protected const SESSION_KEY = 'cav7TicketWebhookToken';

    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('nfTickets');
    }

    public function actionIndex(): AbstractReply
    {
        $hooks = $this->getHookRepo()->findHooksForList()->fetch();

        return $this->view(
            'Cav7\TicketWebhook:Hook\List',
            'cav7_ticket_webhook_list',
            ['hooks' => $hooks]
        );
    }

    public function actionAdd(): AbstractReply
    {
        /** @var HookEntity $hook */
        $hook = $this->em()->create('Cav7\TicketWebhook:Hook');

        return $this->hookAddEdit($hook);
    }

    public function actionEdit(ParameterBag $params): AbstractReply
    {
        return $this->hookAddEdit($this->assertHookExists($params->hook_id));
    }

    protected function hookAddEdit(HookEntity $hook): AbstractReply
    {
        return $this->view(
            'Cav7\TicketWebhook:Hook\Edit',
            'cav7_ticket_webhook_edit',
            [
                'hook' => $hook,
                'categories' => $this->getOpenableCategoryTitlePairs(),
                'urlWithoutToken' => $hook->isInsert() ? '' : $this->hookUrl($hook),
            ]
        );
    }

    /**
     * The hook's public URL, on the public router: the admin router would
     * build an admin.php address. With a token, the URL a caller posts to;
     * without one, the URL a caller posts to with the token in a bearer
     * header.
     */
    protected function hookUrl(HookEntity $hook, ?string $token = null): string
    {
        $data = ['hook_id' => $hook->hook_id];
        if ($token !== null) {
            $data['token'] = $token;
        }

        return $this->app->router('public')->buildLink('canonical:ticket-webhooks', $data);
    }

    public function actionSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        if ($params->hook_id) {
            $hook = $this->assertHookExists($params->hook_id);
            $token = null;
        } else {
            /** @var HookEntity $hook */
            $hook = $this->em()->create('Cav7\TicketWebhook:Hook');
            $token = Token::generate();
            $hook->token_hash = Token::hash($token);
        }

        $this->hookSaveProcess($hook)->run();

        if ($token === null) {
            return $this->redirect($this->buildLink('ticket-webhooks'));
        }

        return $this->redirectToTokenScreen($hook, $token);
    }

    protected function hookSaveProcess(HookEntity $hook): FormAction
    {
        $form = $this->formAction();

        $input = $this->filter([
            'name' => 'str',
            'ticket_category_id' => 'uint',
            'active' => 'bool',
        ]);

        $form->basicEntitySave($hook, $input);

        // After the entity's own checks, so the category relation is set.
        $form->validate(function (FormAction $form) use ($hook) {
            foreach ($this->refusals($hook) as $refusal) {
                $form->logError($refusal);
            }
        });

        return $form;
    }

    /**
     * Why this hook could never open its ticket, found now rather than when
     * the first post arrives. Each entry is a phrase with the reason.
     */
    protected function refusals(HookEntity $hook): array
    {
        $repo = $this->getHookRepo();
        $opener = $repo->findOpener();
        if (!$opener) {
            return [\XF::phrase('cav7_tw_opener_user_id_x_names_no_user', ['id' => $repo->openerUserId()])];
        }

        $category = $hook->Category;
        if (!$category) {
            return [\XF::phrase('cav7_tw_category_not_found')];
        }

        $refusals = [];

        $error = null;
        $canCreate = \XF::asVisitor($opener, function () use ($category, &$error) {
            return $category->canCreateTicket($error);
        });
        if (!$canCreate) {
            $refusals[] = \XF::phrase('cav7_tw_opener_x_may_not_open_a_ticket_in_category_y_because_z', [
                'opener' => $opener->username,
                'category' => $category->title,
                'reason' => $error ? (string) $error : \XF::phrase('cav7_tw_the_opener_has_no_create_permission_there'),
            ]);
        }

        if ($category->require_prefix && !$category->default_prefix_id) {
            $refusals[] = \XF::phrase('cav7_tw_category_x_requires_a_prefix_and_has_no_default', [
                'category' => $category->title,
            ]);
        }

        return $refusals;
    }

    /**
     * Replace the hook's token. GET asks; POST generates the new token, stores
     * its hash, and goes to the screen that shows it once. The old token
     * stops working the moment the hash is written.
     */
    public function actionReplaceToken(ParameterBag $params): AbstractReply
    {
        $hook = $this->assertHookExists($params->hook_id);

        if (!$this->isPost()) {
            return $this->view(
                'Cav7\TicketWebhook:Hook\ReplaceToken',
                'cav7_ticket_webhook_replace_token',
                ['hook' => $hook]
            );
        }

        $token = Token::generate();
        $hook->token_hash = Token::hash($token);
        $hook->save();

        return $this->redirectToTokenScreen($hook, $token);
    }

    /**
     * The token, shown once, with the full URL to paste into the caller.
     * Nothing waiting in the session means it was already shown.
     */
    public function actionToken(ParameterBag $params): AbstractReply
    {
        $hook = $this->assertHookExists($params->hook_id);

        $session = $this->session();
        $waiting = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if (!is_array($waiting) || ($waiting['hook_id'] ?? 0) !== $hook->hook_id) {
            return $this->redirect($this->buildLink('ticket-webhooks'));
        }

        return $this->view(
            'Cav7\TicketWebhook:Hook\Token',
            'cav7_ticket_webhook_token',
            [
                'hook' => $hook,
                'token' => $waiting['token'],
                'url' => $this->hookUrl($hook, $waiting['token']),
                'urlWithoutToken' => $this->hookUrl($hook),
            ]
        );
    }

    protected function redirectToTokenScreen(HookEntity $hook, string $token): AbstractReply
    {
        $this->session()->set(self::SESSION_KEY, [
            'hook_id' => $hook->hook_id,
            'token' => $token,
        ]);

        return $this->redirect($this->buildLink('ticket-webhooks/token', $hook));
    }

    public function actionDelete(ParameterBag $params): AbstractReply
    {
        $hook = $this->assertHookExists($params->hook_id);

        /** @var DeletePlugin $plugin */
        $plugin = $this->plugin(DeletePlugin::class);

        return $plugin->actionDelete(
            $hook,
            $this->buildLink('ticket-webhooks/delete', $hook),
            $this->buildLink('ticket-webhooks/edit', $hook),
            $this->buildLink('ticket-webhooks'),
            $hook->name
        );
    }

    public function actionToggle(): AbstractReply
    {
        /** @var TogglePlugin $plugin */
        $plugin = $this->plugin(TogglePlugin::class);

        return $plugin->actionToggle('Cav7\TicketWebhook:Hook');
    }

    /**
     * Title pairs for the categories that allow tickets to be opened, in the
     * vendor's own display order. A category closed for opening is left out
     * so a hook cannot be pointed at one.
     */
    protected function getOpenableCategoryTitlePairs(): array
    {
        return $this->finder('NF\Tickets:Category')
            ->where('allow_opening', 1)
            ->order('display_order')
            ->fetch()
            ->pluckNamed('title', 'ticket_category_id');
    }

    protected function assertHookExists(int $hookId): HookEntity
    {
        /** @var HookEntity|null $hook */
        $hook = $this->em()->find('Cav7\TicketWebhook:Hook', $hookId);
        if (!$hook) {
            throw $this->exception($this->notFound(\XF::phrase('cav7_tw_hook_not_found')));
        }

        return $hook;
    }

    protected function getHookRepo(): HookRepo
    {
        return $this->repository('Cav7\TicketWebhook:Hook');
    }
}
