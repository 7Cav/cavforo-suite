<?php

namespace Cav7\TicketWebhook\Repository;

use Cav7\TicketWebhook\Entity\Hook;
use NF\Tickets\Entity\Ticket;
use XF\Mvc\Entity\Repository;

/**
 * A hook's alert keys: one row in TABLE per hook and key hash, naming the
 * ticket the key links, and a lock per key so calls with one key run one at
 * a time. See GLOSSARY.md for alert key and link.
 *
 * A hook owns its rows. Deleting the hook deletes them and leaves the
 * tickets. Replacing the token and toggling the hook save the hook's own row
 * and leave them alone.
 */
class AlertKey extends Repository
{
    public const TABLE = 'xf_cav7_ticket_webhook_alert_key';

    /**
     * How long a call waits for another call with its key to finish, in
     * seconds. Opening a ticket or replying takes well under one. A call that
     * waits this long has done nothing, so it answers 500 and a caller that
     * retries loses nothing.
     */
    public const LOCK_WAIT = 10;

    /**
     * Take the hook's lock on a key hash, waiting up to LOCK_WAIT while
     * another call holds it, so calls with one key run one at a time. Calls
     * with other keys take other locks and do not wait.
     *
     * The lock is a database named lock, GET_LOCK. It belongs to the DB
     * connection, not to a transaction, so the Creator's and the Replier's
     * own commits leave it held. The server frees it when the connection
     * closes, so a request that dies holding it lets go of it. XenForo's
     * replication adapter sends lock queries to the write server, so every
     * call meets the same lock.
     *
     * Names are server-wide, and MySQL refuses one over 64 characters. This
     * one carries the hook id and the first half of the hash, 50 characters
     * at most. Two keys whose names collide only wait on each other.
     *
     * @throws \RuntimeException when the lock stayed taken past the wait
     */
    public function lock(Hook $hook, string $keyHash): void
    {
        $taken = $this->db()->fetchOne('SELECT GET_LOCK(?, ?)', [$this->lockName($hook, $keyHash), self::LOCK_WAIT]);
        if ((int) $taken !== 1) {
            throw new \RuntimeException(sprintf('the key stayed locked for %d seconds', self::LOCK_WAIT));
        }
    }

    /**
     * Release the lock lock() took. Never throws, because it runs after a
     * call has done its work and the caller must still hear success. The
     * query fails only on a broken connection, and the server has freed the
     * lock with it.
     */
    public function unlock(Hook $hook, string $keyHash): void
    {
        try {
            $this->db()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lockName($hook, $keyHash)]);
        } catch (\Throwable $e) {
        }
    }

    protected function lockName(Hook $hook, string $keyHash): string
    {
        return 'cav7tw:' . $hook->hook_id . ':' . bin2hex(substr($keyHash, 0, 16));
    }

    /**
     * The ticket a key hash links on the hook, or null when it links none or
     * the ticket is gone, as a ticket merged into another is.
     */
    public function linkedTicket(Hook $hook, string $keyHash): ?Ticket
    {
        $ticketId = (int) $this->db()->fetchOne(
            'SELECT ticket_id FROM ' . self::TABLE . ' WHERE hook_id = ? AND key_hash = ?',
            [$hook->hook_id, $keyHash]
        );

        /** @var Ticket|null $ticket */
        $ticket = $ticketId ? $this->em->find('NF\Tickets:Ticket', $ticketId) : null;

        return $ticket;
    }

    /**
     * Link a key hash to a ticket on the hook, replacing whatever ticket it
     * linked before.
     */
    public function link(Hook $hook, string $keyHash, int $ticketId): void
    {
        $this->db()->insert(self::TABLE, [
            'hook_id' => $hook->hook_id,
            'key_hash' => $keyHash,
            'ticket_id' => $ticketId,
        ], true);
    }

    /**
     * Delete every key of a hook, leaving the tickets they linked.
     */
    public function deleteForHook(int $hookId): void
    {
        $this->db()->delete(self::TABLE, 'hook_id = ?', $hookId);
    }
}
