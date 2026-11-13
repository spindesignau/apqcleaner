<?php

namespace App\Support;

use App\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * The pay period (weekly / fortnightly / monthly) containing a given date, in local time.
 * Fortnights are counted from the `pay_period_anchor` setting (a Monday).
 */
final class PayPeriod
{
    public function __construct(public readonly Carbon $start, public readonly Carbon $end) {}

    public static function containing(?Carbon $date = null): self
    {
        $tz = config('apq.display_timezone');
        $date = ($date ?? Carbon::now($tz))->copy()->tz($tz)->startOfDay();
        $settings = app(Settings::class);
        $type = $settings->get('pay_period', 'fortnightly');

        if ($type === 'monthly') {
            return new self($date->copy()->startOfMonth(), $date->copy()->endOfMonth());
        }
        if ($type === 'weekly') {
            return new self($date->copy()->startOfWeek(), $date->copy()->endOfWeek());
        }

        $anchor = Carbon::parse($settings->get('pay_period_anchor', '2026-01-05'), $tz)->startOfWeek();
        $weeks = intdiv((int) $anchor->diffInDays($date->copy()->startOfWeek(), false), 7);
        $start = $anchor->copy()->addWeeks($weeks - (((($weeks % 2) + 2) % 2)));

        return new self($start, $start->copy()->addDays(13)->endOfDay());
    }
}
