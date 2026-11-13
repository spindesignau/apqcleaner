<?php

namespace App\Support;

use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;

/**
 * An ISO week (Mon–Sun) in the display timezone, addressed as "2026-W46".
 */
final class Week
{
    public function __construct(public readonly Carbon $start, public readonly Carbon $end) {}

    public static function fromParam(?string $param): self
    {
        $tz = config('apq.display_timezone');
        $start = $param && preg_match('/^(\d{4})-W(\d{2})$/', $param, $m)
            ? Carbon::now($tz)->setISODate((int) $m[1], (int) $m[2])->startOfWeek()
            : Carbon::now($tz)->startOfWeek();

        return new self($start, $start->copy()->endOfWeek());
    }

    public function param(): string
    {
        return $this->start->format('o-\WW');
    }

    public function previous(): string
    {
        return $this->start->copy()->subWeek()->format('o-\WW');
    }

    public function next(): string
    {
        return $this->start->copy()->addWeek()->format('o-\WW');
    }

    public function isCurrent(): bool
    {
        return $this->start->isSameWeek(Carbon::now($this->start->tz));
    }

    /** @return Carbon[] */
    public function days(): array
    {
        return CarbonPeriod::create($this->start, 7)->toArray();
    }
}
