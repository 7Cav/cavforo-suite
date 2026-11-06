<?php

namespace Cav7\Citations\Cron;

/**
 * Enqueues the sweep job. The entry ships inactive, because an active cron would start
 * the first sweep under traffic. An operator enables it after the first CLI run.
 */
class Sweep
{
    public static function run(): void
    {
        \XF::app()->jobManager()->enqueueUnique('cav7CitationsSweep', \Cav7\Citations\Job\Sweep::class, [], false);
    }
}
