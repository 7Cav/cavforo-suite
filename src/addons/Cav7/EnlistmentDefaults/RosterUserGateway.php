<?php

namespace Cav7\EnlistmentDefaults;

use NF\Rosters\Entity\RosterUser;
use NF\Rosters\Entity\ServiceRecord;

/** The production EnlistmentGateway, against a real milpac. */
class RosterUserGateway implements EnlistmentGateway
{
    public function __construct(
        private RosterUser $rosterUser
    ) {}

    public function pucSyncOn(): bool
    {
        return PucSync::isOn();
    }

    public function syncPucRows(): void
    {
        $options = \XF::options();

        PucSync::forApp(\XF::app())->milpac(
            $this->rosterUser,
            EnlistmentDecisions::attributionUserId((int) \XF::visitor()->user_id, (int) $options->cav7EnlistDefSystemUserId),
            fn (\Throwable $e, string $context) => $this->logFailure($e, $context),
        );
    }

    public function enlistmentRecordTypeId(): int
    {
        return (int) \XF::options()->cav7EnlistDefRecordTypeId;
    }

    public function creationDate(): int
    {
        return (int) $this->rosterUser->added_date;
    }

    public function joinDate(): string
    {
        return (string) ($this->rosterUser->custom_fields->joinDate ?? '');
    }

    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void
    {
        /** @var ServiceRecord $record */
        $record = $this->rosterUser->getNewServiceRecord();
        $record->record_type_id = $recordTypeId;
        $record->details = $body;
        $record->record_date = $recordDate;
        $record->save();
    }

    /**
     * Never with a rollback: a failure here must not undo the milpac save.
     *
     * Re-check on a dev stack after an NF/Rosters or XenForo upgrade: the entry still names
     * the milpac and the member, and keeps the exception's class and stack trace.
     */
    public function logFailure(\Throwable $e, string $context): void
    {
        \XF::logException($e, false, sprintf(
            'Cav7/EnlistmentDefaults: milpac %d (user %d): %s: ',
            (int) $this->rosterUser->relation_id,
            (int) $this->rosterUser->user_id,
            $context
        ));
    }
}
