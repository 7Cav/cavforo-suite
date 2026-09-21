<?php

namespace Cav7\TicketWebhook\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class Hook extends Repository
{
    public function findHooksForList(): Finder
    {
        return $this->finder('Cav7\TicketWebhook:Hook')
            ->with(['Category', 'LastTicket'])
            ->order('name');
    }

    /**
     * The opener: the user every hook ticket is opened as, from the
     * cav7TicketWebhookOpenerUserId option. Null when the option names no
     * user, which both the public route and the ACP save treat as a refusal.
     */
    public function findOpener(): ?\XF\Entity\User
    {
        $openerId = $this->openerUserId();

        /** @var \XF\Entity\User|null $opener */
        $opener = $openerId ? $this->em->find('XF:User', $openerId) : null;

        return $opener;
    }

    /**
     * The opener user id as configured, whether or not it names a user.
     */
    public function openerUserId(): int
    {
        return (int) (\XF::options()->cav7TicketWebhookOpenerUserId ?? 0);
    }
}
