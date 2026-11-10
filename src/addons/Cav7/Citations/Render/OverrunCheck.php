<?php

namespace Cav7\Citations\Render;

/** Only the right edge can grow, since a line starts at its stored `x`. */
final class OverrunCheck
{
    public static function past(PlannedLine $planned, float $measured): float
    {
        return $planned->line->x + $measured - $planned->boxRight;
    }

    /** The worst line more than the tolerance past its box, or null. */
    public static function worst(DrawPlan $plan, FontMetrics $metrics): ?Overrun
    {
        $worst = null;
        foreach ($plan->steps as $planned) {
            if (!$planned instanceof PlannedLine) {
                continue;
            }
            $line = $planned->line;
            $past = self::past($planned, $metrics->width($planned->fontPath, $planned->size, $line->t, $line->iw));
            if ($past > Typesetter::OVERRUN_TOLERANCE && $past > ($worst?->pixels ?? 0.0)) {
                $worst = new Overrun($planned->fieldKey, $past);
            }
        }
        return $worst;
    }
}
