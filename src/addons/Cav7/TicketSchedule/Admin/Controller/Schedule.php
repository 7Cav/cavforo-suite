<?php

namespace Cav7\TicketSchedule\Admin\Controller;

use Cav7\TicketSchedule\Cadence;
use Cav7\TicketSchedule\Entity\Schedule as ScheduleEntity;
use Cav7\TicketSchedule\Repository\Schedule as ScheduleRepo;
use XF\ControllerPlugin\DeletePlugin;
use XF\ControllerPlugin\TogglePlugin;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/**
 * The ticket schedule screens: list, add, edit, delete and toggle active.
 *
 * Guarded by the vendor's `nfTickets` admin permission, so whoever manages
 * ticket categories manages schedules too. The addon ships no admin
 * permission of its own.
 */
class Schedule extends \XF\Admin\Controller\AbstractController
{
    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('nfTickets');
    }

    public function actionIndex(): AbstractReply
    {
        $schedules = $this->getScheduleRepo()->findSchedulesForList()->fetch();

        return $this->view(
            'Cav7\TicketSchedule:Schedule\List',
            'cav7_ticket_schedule_list',
            ['schedules' => $schedules]
        );
    }

    public function actionAdd(): AbstractReply
    {
        /** @var ScheduleEntity $schedule */
        $schedule = $this->em()->create('Cav7\TicketSchedule:Schedule');
        $schedule->start_date = $this->getScheduleRepo()->today();
        $schedule->cadence_unit = Cadence::UNIT_YEARLY;

        return $this->scheduleAddEdit($schedule);
    }

    public function actionEdit(ParameterBag $params): AbstractReply
    {
        return $this->scheduleAddEdit($this->assertScheduleExists($params->schedule_id));
    }

    protected function scheduleAddEdit(ScheduleEntity $schedule): AbstractReply
    {
        return $this->view(
            'Cav7\TicketSchedule:Schedule\Edit',
            'cav7_ticket_schedule_edit',
            [
                'schedule' => $schedule,
                'categories' => $this->getOpenableCategoryTitlePairs(),
                'units' => Cadence::UNITS,
            ]
        );
    }

    public function actionSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        if ($params->schedule_id)
        {
            $schedule = $this->assertScheduleExists($params->schedule_id);
        }
        else
        {
            /** @var ScheduleEntity $schedule */
            $schedule = $this->em()->create('Cav7\TicketSchedule:Schedule');
        }

        $this->scheduleSaveProcess($schedule)->run();

        return $this->redirect($this->buildLink('ticket-schedules'));
    }

    protected function scheduleSaveProcess(ScheduleEntity $schedule): FormAction
    {
        $form = $this->formAction();

        $input = $this->filter([
            'title' => 'str',
            'message' => 'str',
            'ticket_category_id' => 'uint',
            'start_date' => 'str',
            'cadence_unit' => 'str',
            'cadence_every' => 'uint',
            'active' => 'bool',
        ]);

        // N is read only for the days unit. Store 1 for the others so a later
        // switch to days starts from a sane value rather than a stale one.
        if ($input['cadence_unit'] !== Cadence::UNIT_DAYS)
        {
            $input['cadence_every'] = 1;
        }

        $form->basicEntitySave($schedule, $input);

        // After the entity's own checks, so the category relation is set.
        $form->validate(function (FormAction $form) use ($schedule)
        {
            foreach ($this->refusals($schedule) as $refusal)
            {
                $form->logError($refusal);
            }
        });

        return $form;
    }

    /**
     * Why this schedule could never open its ticket, found now rather than on
     * the due date a year from now. Each entry is a phrase with the reason.
     */
    protected function refusals(ScheduleEntity $schedule): array
    {
        $openerId = (int) (\XF::options()->cav7TicketScheduleOpenerUserId ?? 0);
        /** @var \XF\Entity\User|null $opener */
        $opener = $openerId ? $this->em()->find('XF:User', $openerId) : null;
        if (!$opener)
        {
            return [\XF::phrase('cav7_ts_opener_user_id_x_names_no_user', ['id' => $openerId])];
        }

        $category = $schedule->Category;
        if (!$category)
        {
            return [\XF::phrase('cav7_ts_category_not_found')];
        }

        $refusals = [];

        $error = null;
        $canCreate = \XF::asVisitor($opener, function () use ($category, &$error)
        {
            return $category->canCreateTicket($error);
        });
        if (!$canCreate)
        {
            $refusals[] = \XF::phrase('cav7_ts_opener_x_may_not_open_a_ticket_in_category_y_because_z', [
                'opener' => $opener->username,
                'category' => $category->title,
                'reason' => $error ? (string) $error : \XF::phrase('cav7_ts_the_opener_has_no_create_permission_there'),
            ]);
        }

        if ($category->require_prefix && !$category->default_prefix_id)
        {
            $refusals[] = \XF::phrase('cav7_ts_category_x_requires_a_prefix_and_has_no_default', [
                'category' => $category->title,
            ]);
        }

        return $refusals;
    }

    public function actionDelete(ParameterBag $params): AbstractReply
    {
        $schedule = $this->assertScheduleExists($params->schedule_id);

        /** @var DeletePlugin $plugin */
        $plugin = $this->plugin(DeletePlugin::class);

        return $plugin->actionDelete(
            $schedule,
            $this->buildLink('ticket-schedules/delete', $schedule),
            $this->buildLink('ticket-schedules/edit', $schedule),
            $this->buildLink('ticket-schedules'),
            $schedule->title
        );
    }

    public function actionToggle(): AbstractReply
    {
        /** @var TogglePlugin $plugin */
        $plugin = $this->plugin(TogglePlugin::class);

        return $plugin->actionToggle('Cav7\TicketSchedule:Schedule');
    }

    /**
     * Title pairs for the categories that allow tickets to be opened, in the
     * vendor's own display order. A category closed for opening is left out
     * so a schedule cannot be pointed at one.
     */
    protected function getOpenableCategoryTitlePairs(): array
    {
        return $this->finder('NF\Tickets:Category')
            ->where('allow_opening', 1)
            ->order('display_order')
            ->fetch()
            ->pluckNamed('title', 'ticket_category_id');
    }

    protected function assertScheduleExists(int $scheduleId): ScheduleEntity
    {
        /** @var ScheduleEntity|null $schedule */
        $schedule = $this->em()->find('Cav7\TicketSchedule:Schedule', $scheduleId);
        if (!$schedule)
        {
            throw $this->exception($this->notFound(\XF::phrase('cav7_ts_schedule_not_found')));
        }

        return $schedule;
    }

    protected function getScheduleRepo(): ScheduleRepo
    {
        return $this->repository('Cav7\TicketSchedule:Schedule');
    }
}
