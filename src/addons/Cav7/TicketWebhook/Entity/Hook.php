<?php

namespace Cav7\TicketWebhook\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A hook: one address a caller can post to, and the record behind it. See
 * CONTEXT.md for the terms.
 *
 * The row carries the name, the category and the token's hash, and nothing
 * else about the ticket. Priority, status and prefix come from the category
 * and the vendor's option defaults when a post opens one.
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
    /**
     * The row as HookPost reads it: whether it opens anything, what a
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

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_ticket_webhook_hook';
        $structure->shortName = 'Cav7\TicketWebhook:Hook';
        $structure->primaryKey = 'hook_id';
        $structure->columns = [
            'hook_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            // 150 is the width of xf_nf_tickets_ticket.title, because the
            // name is the title of a post that carries none of its own.
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
