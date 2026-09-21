<?php

namespace Cav7\TicketSchedule\Entity;

use Cav7\TicketSchedule\Cadence;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A ticket schedule: one record that names a ticket to open and the cadence to
 * open it on. See CONTEXT.md for the terms.
 *
 * The row carries the title, first message and category, and nothing else about
 * the ticket. Priority, status and prefix come from the category and the
 * vendor's option defaults when the cron opens it.
 *
 * COLUMNS
 * @property int|null $schedule_id
 * @property string $title
 * @property string $message
 * @property int $ticket_category_id
 * @property string $start_date calendar day, Y-m-d
 * @property string $cadence_unit one of Cadence::UNITS
 * @property int $cadence_every read only for the days unit
 * @property bool $active
 * @property string $due_date calendar day, Y-m-d, in the board's timezone
 * @property int $last_ticket_id
 * @property int $last_ticket_date
 *
 * GETTERS
 * @property-read \XF\Phrase $cadence_label
 *
 * RELATIONS
 * @property-read \NF\Tickets\Entity\Category|null $Category
 * @property-read \NF\Tickets\Entity\Ticket|null $LastTicket
 */
class Schedule extends Entity
{
    /**
     * The row's cadence as a value. Throws \InvalidArgumentException when the
     * row cannot be counted from; _preSave turns that into an entity error.
     *
     * The casts matter: a value XenForo has refused at the column, such as a
     * unit outside allowedValues, is null on the entity, and null is not a
     * string the constructor accepts.
     */
    public function getCadence(): Cadence
    {
        return new Cadence((string) $this->start_date, (string) $this->cadence_unit, (int) $this->cadence_every);
    }

    /**
     * The cadence as the ACP shows it: "Yearly", or "Every 10 days".
     */
    public function getCadenceLabel(): \XF\Phrase
    {
        if ($this->cadence_unit === Cadence::UNIT_DAYS) {
            return \XF::phrase('cav7_ts_every_x_days', ['n' => $this->cadence_every]);
        }

        return \XF::phrase('cav7_ts_cadence_' . $this->cadence_unit);
    }

    /**
     * The due date is recomputed from the start date and cadence on insert,
     * when either changes, and when an inactive schedule is set active. Editing
     * only the title or message leaves it alone: a recompute on a day whose
     * ticket has already opened would land on today again and open a second.
     *
     * The same guard applies where a recompute is due: a recomputed due date
     * that equals the day the last ticket opened moves on one cadence step, so
     * a cadence edit or a reactivation on that day cannot open a duplicate.
     */
    protected function _preSave(): void
    {
        if ($this->hasErrors()) {
            // A column has already refused its value and said so. A second
            // message about the same field would only repeat it.
            return;
        }

        try {
            $cadence = $this->getCadence();
        } catch (\InvalidArgumentException $e) {
            $this->error(\XF::phrase('please_enter_valid_date_format'), 'start_date');
            return;
        }

        $recompute = $this->isInsert()
            || $this->isChanged(['start_date', 'cadence_unit', 'cadence_every'])
            || ($this->isChanged('active') && $this->active);

        if (!$recompute) {
            return;
        }

        $repo = $this->getScheduleRepo();
        $due = $cadence->firstDueOnOrAfter($repo->today());
        if ($this->last_ticket_date && $due === $repo->dayOf($this->last_ticket_date)) {
            $due = $cadence->dueAfter($due);
        }

        $this->due_date = $due;
    }

    protected function getScheduleRepo(): \Cav7\TicketSchedule\Repository\Schedule
    {
        return $this->repository('Cav7\TicketSchedule:Schedule');
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_ticket_schedule';
        $structure->shortName = 'Cav7\TicketSchedule:Schedule';
        $structure->primaryKey = 'schedule_id';
        $structure->columns = [
            'schedule_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            // 150 is the width of xf_nf_tickets_ticket.title, so the ticket's
            // title is never trimmed behind the admin's back.
            'title' => ['type' => self::STR, 'required' => 'please_enter_valid_title', 'maxLength' => 150],
            'message' => ['type' => self::STR, 'required' => 'please_enter_valid_message'],
            'ticket_category_id' => ['type' => self::UINT, 'required' => true],
            'start_date' => ['type' => self::STR, 'required' => true, 'maxLength' => 10],
            'cadence_unit' => ['type' => self::STR, 'required' => true, 'allowedValues' => Cadence::UNITS],
            'cadence_every' => [
                'type' => self::UINT,
                'default' => 1,
                'min' => Cadence::MIN_EVERY_DAYS,
                'max' => Cadence::MAX_EVERY_DAYS,
            ],
            'active' => ['type' => self::BOOL, 'default' => true],
            'due_date' => ['type' => self::STR, 'default' => '', 'maxLength' => 10],
            'last_ticket_id' => ['type' => self::UINT, 'default' => 0],
            'last_ticket_date' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [
            'cadence_label' => true,
        ];
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
