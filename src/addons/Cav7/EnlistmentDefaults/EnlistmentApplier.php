<?php

namespace Cav7\EnlistmentDefaults;

/**
 * Applies the enlistment defaults to a freshly-created milpac: the standing PUC
 * set (one dated grant per bundled date, each with its citation) and the single
 * Transfer-typed enlistment record.
 *
 * This is the deep module. The decisions (which dates are still pending, who an
 * award is attributed to) come from EnlistmentDecisions; the entity-world work
 * goes through an EnlistmentGateway. The applier owns the orchestration and the
 * fail-open policy: every grant and the record write are isolated, so one
 * failure is logged and never blocks enlistment nor aborts the rest.
 *
 * The caller (the RosterUser entity extension) is responsible for the
 * insert-only gate; by the time apply() runs, this is a new milpac.
 */
class EnlistmentApplier
{
    public function __construct(
        private EnlistmentGateway $gateway
    ) {}

    public function apply(): void
    {
        $this->grantPucSet();
        $this->writeEnlistmentRecord();
    }

    /**
     * Grant each still-missing PUC date, in earned order. Each grant is
     * isolated: a failure is logged and the loop continues, so a single bad
     * citation file or save cannot stop the rest of the set.
     */
    private function grantPucSet(): void
    {
        $awardId = $this->gateway->pucAwardId();
        $fromUserId = EnlistmentDecisions::attributionUserId(
            $this->gateway->visitorUserId(),
            $this->gateway->systemFallbackUserId()
        );

        $pending = EnlistmentDecisions::pendingDates(
            $this->gateway->existingPucAwardDates()
        );

        foreach ($pending as $date) {
            try {
                $this->gateway->grantAward(
                    $awardId,
                    PucSet::awardDateTimestamp($date),
                    $fromUserId,
                    PucSet::citationPath($date)
                );
            } catch (\Throwable $e) {
                $this->gateway->logError(
                    "Cav7/EnlistmentDefaults: failed to grant PUC for $date: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Write the one enlistment record. The record is dated from the milpac's
     * submitted Join Date at midnight UTC (falling back to midnight UTC of the
     * creation day when that field is blank or unparseable), so a backdated
     * enlistment gets a correctly-dated record. Isolated for the same fail-open
     * reason: a failed record write is logged and the milpac save still succeeds.
     */
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
            $this->gateway->logError(
                'Cav7/EnlistmentDefaults: failed to write enlistment record: ' . $e->getMessage()
            );
        }
    }
}
