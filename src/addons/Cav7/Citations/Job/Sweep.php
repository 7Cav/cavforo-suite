<?php

namespace Cav7\Citations\Job;

use Cav7\Citations\Storage\Storage;
use Cav7\Citations\Storage\SweepProgress;
use XF\Job\AbstractJob;
use XF\Job\JobResult;

/**
 * The cron's sweep. The storage lock is held per run() call, because each call may get a
 * new connection. If the lock is taken the job ends, and the next cron tries again. The
 * brake can't be lifted from here.
 */
class Sweep extends AbstractJob
{
    protected $defaultData = ['progress' => []];

    public function run($maxRunTime): JobResult
    {
        $storage = Storage::forApp($this->app);
        if (!$storage->lock()) {
            return $this->complete();
        }

        $progress = SweepProgress::fromArray($this->data['progress']);
        $sweeper = $storage->sweeper(function (string $line): void {
        });
        $start = microtime(true);

        try {
            do {
                $sweeper->advance($progress);
            } while (!$progress->done() && microtime(true) - $start < $maxRunTime);
        } finally {
            $storage->unlock();
        }

        if ($progress->done()) {
            return $this->complete();
        }

        $this->data['progress'] = $progress->toArray();

        return $this->resume();
    }

    public function getStatusMessage(): string
    {
        return 'Sweeping citation files';
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
