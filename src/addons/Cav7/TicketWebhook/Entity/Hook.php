<?php

namespace Cav7\TicketWebhook\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A hook: one address a caller sends calls to, and the record behind it. See
 * GLOSSARY.md for the terms.
 *
 * The row carries the name, the category and the token's hash, and nothing
 * else about the ticket. Priority, status and prefix come from the category
 * and the vendor's option defaults when a call opens one.
 *
 * A hook owns its alert keys: rows in ALERT_KEY_TABLE, one per key hash,
 * each naming the ticket the key links. Deleting the hook deletes them and
 * leaves the tickets. Replacing the token and toggling the hook save this
 * same row and leave them alone.
 *
 * COLUMNS
 * @property int|null $hook_id
 * @property string $name
 * @property int $ticket_category_id
 * @property bool $active
 * @property string $token_hash raw SHA-256 digest, 32 bytes
 * @property int $created_date
 * @property int $last_used_date
 * @property int $last_ticket_id
 *
 * RELATIONS
 * @property-read \NF\Tickets\Entity\Category|null $Category
 * @property-read \NF\Tickets\Entity\Ticket|null $LastTicket
 */
class Hook extends Entity
{
    public const ALERT_KEY_TABLE = 'xf_cav7_ticket_webhook_alert_key';

    /**
     * The row as HookCall reads it: whether it opens anything, what a
     * presented token is matched against, and the title of last resort.
     */
    public function facts(): array
    {
        return [
            'active' => (bool) $this->active,
            'token_hash' => (string) $this->token_hash,
            'name' => (string) $this->name,
        ];
    }

    /**
     * How long a call waits for another call with its key to finish, in
     * seconds. Opening a ticket or replying takes well under one. A call that
     * waits this long has done nothing, so it answers 500 and a caller that
     * retries loses nothing.
     */
    public const KEY_LOCK_WAIT = 10;

    /**
     * Take this hook's lock on a key hash, waiting up to KEY_LOCK_WAIT while
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
    public function lockKey(string $keyHash): void
    {
        $taken = $this->db()->fetchOne('SELECT GET_LOCK(?, ?)', [$this->keyLockName($keyHash), self::KEY_LOCK_WAIT]);
        if ((int) $taken !== 1) {
            throw new \RuntimeException(sprintf('the key stayed locked for %d seconds', self::KEY_LOCK_WAIT));
        }
    }

    /**
     * Release the lock lockKey() took. Never throws, because it runs after a
     * call has done its work and the caller must still hear success. The
     * query fails only on a broken connection, and the server has freed the
     * lock with it.
     */
    public function unlockKey(string $keyHash): void
    {
        try {
            $this->db()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->keyLockName($keyHash)]);
        } catch (\Throwable $e) {
        }
    }

    protected function keyLockName(string $keyHash): string
    {
        return 'cav7tw:' . $this->hook_id . ':' . bin2hex(substr($keyHash, 0, 16));
    }

    /**
     * The ticket a key hash links on this hook, or null when it links none or
     * the ticket is gone, as a ticket merged into another is.
     */
    public function linkedTicket(string $keyHash): ?\NF\Tickets\Entity\Ticket
    {
        $ticketId = (int) $this->db()->fetchOne(
            'SELECT ticket_id FROM ' . self::ALERT_KEY_TABLE . ' WHERE hook_id = ? AND key_hash = ?',
            [$this->hook_id, $keyHash]
        );

        /** @var \NF\Tickets\Entity\Ticket|null $ticket */
        $ticket = $ticketId ? $this->em()->find('NF\Tickets:Ticket', $ticketId) : null;

        return $ticket;
    }

    /**
     * Record a call that reached a ticket: link the key to it, when the call
     * had one, and move the hook list's last used date and last ticket.
     */
    public function recordCall(int $ticketId, ?string $keyHash): void
    {
        if ($keyHash !== null) {
            $this->db()->insert(self::ALERT_KEY_TABLE, [
                'hook_id' => $this->hook_id,
                'key_hash' => $keyHash,
                'ticket_id' => $ticketId,
            ], true);
        }

        $this->fastUpdate([
            'last_used_date' => \XF::$time,
            'last_ticket_id' => $ticketId,
        ]);
    }

    protected function _postDelete(): void
    {
        $this->db()->delete(self::ALERT_KEY_TABLE, 'hook_id = ?', $this->hook_id);
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_ticket_webhook_hook';
        $structure->shortName = 'Cav7\TicketWebhook:Hook';
        $structure->primaryKey = 'hook_id';
        $structure->columns = [
            'hook_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            // 150 is the width of xf_nf_tickets_ticket.title, because the
            // name is the title of a call that carries none of its own.
            'name' => ['type' => self::STR, 'required' => 'please_enter_valid_name', 'maxLength' => 150],
            'ticket_category_id' => ['type' => self::UINT, 'required' => true],
            'active' => ['type' => self::BOOL, 'default' => true],
            'token_hash' => ['type' => self::BINARY, 'required' => true, 'maxLength' => 32],
            'created_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'last_used_date' => ['type' => self::UINT, 'default' => 0],
            'last_ticket_id' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [];
        $structure->relations = [
            'Category' => [
                'entity' => 'NF\Tickets:Category',
                'type' => self::TO_ONE,
                'conditions' => 'ticket_category_id',
                'primary' => true,
            ],
            'LastTicket' => [
                'entity' => 'NF\Tickets:Ticket',
                'type' => self::TO_ONE,
                'conditions' => [['ticket_id', '=', '$last_ticket_id']],
                'primary' => true,
            ],
        ];

        return $structure;
    }
}
