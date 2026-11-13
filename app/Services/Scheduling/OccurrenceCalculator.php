<?php

namespace App\Services\Scheduling;

use App\Enums\Frequency;
use App\Models\RecurringSchedule;
use Carbon\CarbonImmutable;

/**
 * Pure date maths: which calendar dates does a recurring schedule fall on?
 */
class OccurrenceCalculator
{
    /**
     * @return CarbonImmutable[] local dates (time 00:00) within [from, to]
     */
    public function dates(RecurringSchedule $schedule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $anchor = $this->firstOnWeekday(CarbonImmutable::parse($schedule->anchor_date->toDateString()), $schedule->weekday);

        $start = $from->max($anchor);
        $end = $schedule->effective_until
            ? $to->min(CarbonImmutable::parse($schedule->effective_until->toDateString()))
            : $to;

        if ($start->gt($end)) {
            return [];
        }

        return $schedule->frequency === Frequency::MonthlyNthWeekday
            ? $this->monthly($schedule, $start, $end)
            : $this->everyNWeeks($anchor, $schedule->frequency->intervalWeeks(), $start, $end);
    }

    private function everyNWeeks(CarbonImmutable $anchor, int $weeks, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $period = $weeks * 7;
        $daysFromAnchor = (int) $anchor->diffInDays($start);
        $offset = ($period - ($daysFromAnchor % $period)) % $period;   // keeps the fortnight parity of the anchor
        $out = [];
        for ($d = $start->addDays($offset); $d->lte($end); $d = $d->addDays($period)) {
            $out[] = $d;
        }

        return $out;
    }

    private function monthly(RecurringSchedule $schedule, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonthNoOverflow()) {
            $date = $this->nthWeekdayOfMonth($month, $schedule->weekday, (int) $schedule->nth_week);
            if ($date && $date->betweenIncluded($start, $end)) {
                $out[] = $date;
            }
        }

        return $out;
    }

    private function nthWeekdayOfMonth(CarbonImmutable $month, int $isoWeekday, int $nth): ?CarbonImmutable
    {
        if ($nth === -1) {
            $d = $month->endOfMonth()->startOfDay();
            while ((int) $d->isoWeekday() !== $isoWeekday) {
                $d = $d->subDay();
            }

            return $d;
        }
        $d = $this->firstOnWeekday($month->startOfMonth(), $isoWeekday)->addWeeks(max(1, $nth) - 1);

        return $d->month === $month->month ? $d : null;
    }

    public function firstOnWeekday(CarbonImmutable $date, int $isoWeekday): CarbonImmutable
    {
        $diff = ($isoWeekday - $date->isoWeekday() + 7) % 7;

        return $date->startOfDay()->addDays($diff);
    }
}
