<?php

namespace App\Services\Reports;

use App\Enums\EmploymentType;
use App\Enums\PricingModel;
use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Confirmed work (approved or invoiced time) for a period, one row per visit, using the same
 * pricing rules as invoicing so reports and invoices agree:
 *   fixed price → the visit price once (however many cleaners);  hourly → Σ billable minutes × hourly rate.
 * Labour = each cleaner's minutes × the pay rate for that day (snapshot at approval, else current),
 * plus on-costs (labour_on_cost_percent, e.g. super) for employees; ABN contractors have none.
 */
class VisitLedger
{
    public function __construct(private Settings $settings) {}

    /**
     * @return Collection<int, array{appointment_id:int, client_id:int, client:string, date:Carbon, scheduled:int, logged:int,
     *     billable:int, revenue:float, labour:float, labour_with_oncost:float, staff: array<int, array{id:int, name:string, minutes:int, pay:float, oncost:float, day:int}>}>
     */
    public function visits(Carbon $from, Carbon $to, ?int $clientId = null, ?int $staffId = null): Collection
    {
        $onCostPct = (float) $this->settings->get('labour_on_cost_percent', 12);

        return TimeEntry::query()
            ->with(['appointment', 'client', 'user'])
            ->whereIn('status', [TimeEntryStatus::Approved->value, TimeEntryStatus::Invoiced->value])
            ->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->when($staffId, fn ($q) => $q->where('user_id', $staffId))
            ->orderBy('started_at')
            ->get()
            ->groupBy('appointment_id')
            ->map(function (Collection $entries) use ($onCostPct) {
                $first = $entries->first();
                $appointment = $first->appointment;
                $client = $first->client;
                $hourly = ($appointment->pricing_model ?? $client->pricing_model) === PricingModel::Hourly;
                $rate = (float) ($first->charge_rate ?? $appointment->price ?? $client->currentPrice() ?? 0);
                $billable = (int) $entries->sum('billable_minutes');

                $staff = $entries->map(function (TimeEntry $e) use ($onCostPct) {
                    $pay = round(($e->duration_minutes ?? 0) / 60 * (float) ($e->pay_rate ?? $e->user->payRateFor($e->localStart()) ?? 0), 2);
                    $oncost = $e->user->employment_type === EmploymentType::ContractorAbn ? 0.0 : round($pay * $onCostPct / 100, 2);

                    return ['id' => $e->user_id, 'name' => $e->user->name, 'minutes' => (int) $e->duration_minutes,
                        'pay' => $pay, 'oncost' => $oncost, 'day' => (int) $e->localStart()->isoWeekday(), 'entry' => $e];
                })->values()->all();
                $labour = round(array_sum(array_column($staff, 'pay')), 2);

                return [
                    'appointment_id' => $appointment->id,
                    'client_id' => $client->id,
                    'client' => $client->display_name,
                    'date' => $appointment->localStart(),
                    'scheduled' => $appointment->durationMinutes(),
                    'logged' => (int) $entries->sum('duration_minutes'),
                    'billable' => $billable,
                    'revenue' => round($hourly ? $billable / 60 * $rate : $rate, 2),
                    'labour' => $labour,
                    'labour_with_oncost' => round($labour + array_sum(array_column($staff, 'oncost')), 2),
                    'staff' => $staff,
                ];
            })
            ->values();
    }
}
