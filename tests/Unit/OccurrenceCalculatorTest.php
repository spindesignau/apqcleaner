<?php

namespace Tests\Unit;

use App\Enums\Frequency;
use App\Models\RecurringSchedule;
use App\Services\Scheduling\OccurrenceCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OccurrenceCalculatorTest extends TestCase
{
    private function dates(array $attrs, string $from, string $to): array
    {
        $schedule = new RecurringSchedule($attrs + ['start_time' => '09:00', 'duration_minutes' => 120]);

        return array_map(fn ($d) => $d->toDateString(),
            (new OccurrenceCalculator)->dates($schedule, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
    }

    public function test_weekly(): void
    {
        $this->assertSame(['2026-11-17', '2026-11-24', '2026-12-01'],
            $this->dates(['frequency' => Frequency::Weekly, 'weekday' => 2, 'anchor_date' => '2026-11-17'], '2026-11-14', '2026-12-05'));
    }

    public function test_fortnightly_keeps_parity_of_anchor_even_when_window_starts_later(): void
    {
        // Anchor Tue 3 Nov → visits 3 Nov, 17 Nov, 1 Dec, 15 Dec. Window starting 20 Nov must give 1 Dec, 15 Dec.
        $this->assertSame(['2026-12-01', '2026-12-15'],
            $this->dates(['frequency' => Frequency::Fortnightly, 'weekday' => 2, 'anchor_date' => '2026-11-03'], '2026-11-20', '2026-12-20'));
    }

    public function test_anchor_not_on_weekday_moves_to_next_matching_day(): void
    {
        // Anchor Wed 18 Nov, weekday Friday → first visit Fri 20 Nov.
        $this->assertSame(['2026-11-20', '2026-12-11'],
            $this->dates(['frequency' => Frequency::Every3Weeks, 'weekday' => 5, 'anchor_date' => '2026-11-18'], '2026-11-01', '2026-12-20'));
    }

    public function test_every_four_weeks_with_end_date(): void
    {
        $this->assertSame(['2026-11-16', '2026-12-14'],
            $this->dates(['frequency' => Frequency::Every4Weeks, 'weekday' => 1, 'anchor_date' => '2026-11-16', 'effective_until' => '2027-01-10'], '2026-11-01', '2027-03-01'));
    }

    #[DataProvider('monthlyCases')]
    public function test_monthly_nth_weekday(int $nth, array $expected): void
    {
        $this->assertSame($expected,
            $this->dates(['frequency' => Frequency::MonthlyNthWeekday, 'weekday' => 4, 'nth_week' => $nth, 'anchor_date' => '2026-11-01'], '2026-11-01', '2027-01-31'));
    }

    public static function monthlyCases(): array
    {
        return [
            'first Thursday' => [1, ['2026-11-05', '2026-12-03', '2027-01-07']],
            'last Thursday' => [-1, ['2026-11-26', '2026-12-31', '2027-01-28']],
        ];
    }
}
