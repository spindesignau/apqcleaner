<?php

namespace App\Enums;

enum Frequency: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Every3Weeks = 'every_3_weeks';
    case Every4Weeks = 'every_4_weeks';
    case MonthlyNthWeekday = 'monthly_nth_weekday';

    public function label(): string
    {
        return __('enums.frequency.'.$this->value);
    }

    /** Weeks between visits, or null for monthly patterns. */
    public function intervalWeeks(): ?int
    {
        return match ($this) {
            self::Weekly => 1,
            self::Fortnightly => 2,
            self::Every3Weeks => 3,
            self::Every4Weeks => 4,
            self::MonthlyNthWeekday => null,
        };
    }
}
