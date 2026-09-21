<?php

namespace Cav7\TicketSchedule\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class Schedule extends Repository
{
    public function findSchedulesForList(): Finder
    {
        return $this->finder('Cav7\TicketSchedule:Schedule')
            ->with(['Category', 'LastTicket'])
            ->order(['due_date', 'title']);
    }

    /**
     * Active schedules whose due date is $today or earlier. A due date is a
     * calendar day, so "on or before" is a plain comparison of Y-m-d strings,
     * and MySQL compares a DATE column the same way.
     */
    public function findDueSchedules(string $today): Finder
    {
        return $this->finder('Cav7\TicketSchedule:Schedule')
            ->with('Category')
            ->where('active', 1)
            ->where('due_date', '<=', $today)
            ->order('due_date');
    }

    /**
     * The opener: the user every scheduled ticket is opened as, from the
     * cav7TicketScheduleOpenerUserId option. Null when the option names no
     * user, which both the cron and the ACP save treat as a refusal.
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
        return (int) (\XF::options()->cav7TicketScheduleOpenerUserId ?? 0);
    }

    /**
     * Today as a calendar day in the board's timezone. A due date is a day in
     * that zone, so this is the one place the zone is read; Cadence itself
     * never sees one.
     */
    public function today(): string
    {
        return $this->dayOf(\XF::$time);
    }

    /**
     * The calendar day a unix timestamp falls on in the board's timezone.
     */
    public function dayOf(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($this->boardTimeZone())
            ->format('Y-m-d');
    }

    /**
     * The guestTimeZone option, which is what XenForo shows a member with no
     * zone of their own. UTC when the option holds a name PHP does not know,
     * which a stock board cannot produce but a hand-edited xf_option row can.
     */
    protected function boardTimeZone(): \DateTimeZone
    {
        $name = (string) (\XF::options()->guestTimeZone ?? 'UTC');

        try {
            return new \DateTimeZone($name);
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
