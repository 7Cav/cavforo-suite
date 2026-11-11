<?php

namespace Cav7\EnlistmentDefaults;

/**
 * Gives a new milpac its PUC rows, when the PUC sync is on, and its enlistment record.
 *
 * Fail-open: each is isolated as far as the log seam holds, so one failure is logged and
 * does not stop the other. If logFailure itself throws, the rest goes with it; the milpac
 * save survives on the entity extension's last-resort catch either way.
 */
class EnlistmentApplier
{
    public function __construct(
        private EnlistmentGateway $gateway
    ) {}

    public function apply(): void
    {
        $this->syncPucRows();
        $this->writeEnlistmentRecord();
    }

    private function syncPucRows(): void
    {
        try {
            if ($this->gateway->pucSyncOn()) {
                $this->gateway->syncPucRows();
            }
        } catch (\Throwable $e) {
            $this->gateway->logFailure($e, 'failed to sync PUC rows');
        }
    }

    /** Dated from the submitted Join Date, see EnlistmentDecisions::enlistmentRecordDate(). */
    private function writeEnlistmentRecord(): void
    {
        try {
            $recordDate = EnlistmentDecisions::enlistmentRecordDate(
                $this->gateway->joinDate(),
                $this->gateway->creationDate()
            );

            $this->gateway->writeServiceRecord(
                $this->gateway->enlistmentRecordTypeId(),
                EnlistmentDecisions::ENLISTMENT_RECORD_BODY,
                $recordDate
            );
        } catch (\Throwable $e) {
            $this->gateway->logFailure($e, 'failed to write enlistment record');
        }
    }
}
