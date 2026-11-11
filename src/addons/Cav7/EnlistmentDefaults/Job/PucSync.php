<?php

namespace Cav7\EnlistmentDefaults\Job;

use Cav7\EnlistmentDefaults\PucCounts;
use Cav7\EnlistmentDefaults\PucSync as Sync;
use XF\Job\AbstractJob;
use XF\Job\JobResult;

/** Walks every milpac on every roster, and logs the counts on each grant once the walk ends. */
class PucSync extends AbstractJob
{
    protected $defaultData = ['after' => 0, 'counts' => []];

    public function run($maxRunTime): JobResult
    {
        $sync = Sync::forApp($this->app, new PucCounts($this->data['counts']));
        $start = microtime(true);

        while (Sync::isOn()) {
            $relationIds = $sync->nextMilpacs($this->data['after']);
            if ($relationIds === []) {
                break;
            }
            $sync->batch($relationIds, false);
            $this->data['after'] = end($relationIds);

            if (microtime(true) - $start >= $maxRunTime) {
                $this->data['counts'] = $sync->counts->grants;
                return $this->resume();
            }
        }

        $sync->logOnGrants();
        return $this->complete();
    }

    public function getStatusMessage(): string
    {
        return 'Syncing PUC rows';
    }

    public function canCancel(): bool
    {
        return false;
    }

    public function canTriggerByChoice(): bool
    {
        return false;
    }
}
