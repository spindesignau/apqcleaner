<?php

namespace App\Actions\Invoicing;

use App\Enums\AppointmentStatus;
use App\Enums\PricingModel;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\TimeEntry;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns approved, uninvoiced time into local invoices (one per client, or one per visit for
 * "per visit" clients) and locks the time entries. Pushing to Xero is a separate, retryable job.
 *
 * Lines: one per visit.
 *   fixed price → qty 1 × the visit price (all cleaners on that visit are covered by the one price)
 *   hourly      → qty = Σ billable minutes / 60 (all cleaners) × the client's hourly rate
 * Amounts are GST-exclusive; GST 10 % ("OUTPUT" = GST on Income).
 */
class BuildInvoices
{
    public const GST_RATE = 0.10;

    public function __construct(private Settings $settings) {}

    /**
     * Preview without saving: per client, the visits that would be invoiced.
     *
     * @return Collection<int, array{client: Client, visits: Collection, subtotal: float, gst: float, total: float, warnings: string[]}>
     */
    public function preview(Carbon $from, Carbon $to, ?string $frequency = null): Collection
    {
        return $this->approvedEntries($from, $to, $frequency)
            ->groupBy('client_id')
            ->map(function (Collection $entries) use ($from, $to) {
                $client = $entries->first()->client;
                $visits = $this->visits($entries);
                $subtotal = round($visits->sum('amount'), 2);
                $warnings = [];
                if ($visits->contains(fn ($v) => $v['unit'] === null)) {
                    $warnings[] = __('invoices.warn_no_price');
                }
                if (! $client->email) {
                    $warnings[] = __('invoices.warn_no_email');
                }
                $pending = TimeEntry::where('client_id', $client->id)->whereIn('status', [TimeEntryStatus::Submitted->value, TimeEntryStatus::Stopped->value, TimeEntryStatus::Running->value])
                    ->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()])->count();
                if ($pending) {
                    $warnings[] = trans_choice('invoices.warn_unapproved', $pending, ['count' => $pending]);
                }

                return [
                    'client' => $client,
                    'visits' => $visits,
                    'subtotal' => $subtotal,
                    'gst' => round($subtotal * self::GST_RATE, 2),
                    'total' => round($subtotal * (1 + self::GST_RATE), 2),
                    'warnings' => $warnings,
                ];
            })
            ->sortBy(fn ($row) => $row['client']->display_name)
            ->values();
    }

    /**
     * @param  int[]|null  $clientIds  limit to these clients (null = all with approved time)
     * @return Collection<int, Invoice>
     */
    public function build(Carbon $from, Carbon $to, string $mode, bool $sendEmail, ?int $runId = null, ?array $clientIds = null, ?string $frequency = null, ?int $userId = null): Collection
    {
        $entries = $this->approvedEntries($from, $to, $frequency)
            ->when($clientIds !== null, fn ($c) => $c->whereIn('client_id', $clientIds));

        return DB::transaction(function () use ($entries, $from, $to, $mode, $sendEmail, $runId, $userId) {
            $invoices = collect();
            foreach ($entries->groupBy('client_id') as $clientEntries) {
                $client = $clientEntries->first()->client;
                // "Per visit" clients get one invoice per visit; everyone else one invoice for the period.
                $groups = $client->invoice_frequency === 'per_visit'
                    ? $clientEntries->groupBy('appointment_id')->values()
                    : collect([$clientEntries]);

                foreach ($groups as $group) {
                    $visits = $this->visits($group);
                    if ($visits->contains(fn ($v) => $v['unit'] === null)) {
                        continue;   // no price → left for the admin to fix; shown as a warning in the preview
                    }
                    $invoices->push($this->createInvoice($client, $visits, $group, $from, $to, $mode, $sendEmail, $runId, $userId));
                }
            }

            return $invoices;
        });
    }

    private function createInvoice(Client $client, Collection $visits, Collection $entries, Carbon $from, Carbon $to, string $mode, bool $sendEmail, ?int $runId, ?int $userId): Invoice
    {
        $tz = config('apq.display_timezone');
        $issue = Carbon::now($tz)->startOfDay();
        $subtotal = round($visits->sum('amount'), 2);
        $periodLabel = $visits->count() === 1 ? $visits->first()['date']->format('Ymd') : $from->format('Ym');

        $invoice = Invoice::create([
            'invoice_run_id' => $runId,
            'client_id' => $client->id,
            'type' => 'service',
            'reference' => Str::limit('APQ-'.$periodLabel.'-'.$client->id, 60, ''),
            'idempotency_key' => (string) Str::uuid(),
            'issue_date' => $issue->toDateString(),
            'due_date' => $issue->copy()->addDays((int) $this->settings->get('xero.payment_terms_days', 7))->toDateString(),
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'subtotal' => $subtotal,
            'gst' => round($subtotal * self::GST_RATE, 2),
            'total' => round($subtotal * (1 + self::GST_RATE), 2),
            'mode' => $mode,
            'send_email' => $sendEmail && $mode === 'authorised',
            'sync_status' => Invoice::SYNC_PENDING,
            'created_by' => $userId,
        ]);

        $accountCode = (string) ($client->serviceType?->xero_account_code ?: $this->settings->get('xero.default_account_code', '200'));
        foreach ($visits->values() as $i => $visit) {
            $line = InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'appointment_id' => $visit['appointment']->id,
                'description' => $visit['description'],
                'quantity' => $visit['quantity'],
                'unit_amount' => $visit['unit'],
                'account_code' => $accountCode,
                'tax_type' => 'OUTPUT',
                'line_amount' => $visit['amount'],
                'sort' => $i,
            ]);
            TimeEntry::whereIn('id', $visit['entry_ids'])->update([
                'invoice_line_id' => $line->id,
                'status' => TimeEntryStatus::Invoiced->value,
            ]);
            Appointment::whereKey($visit['appointment']->id)->update(['status' => AppointmentStatus::Invoiced->value]);
        }
        Audit::log('invoice.created', $invoice, after: ['total' => $invoice->total, 'lines' => $visits->count()]);

        return $invoice;
    }

    /** @return Collection<int, TimeEntry> */
    private function approvedEntries(Carbon $from, Carbon $to, ?string $frequency): Collection
    {
        return TimeEntry::query()
            ->with(['client.serviceType', 'appointment.serviceType', 'user:id,name'])
            ->where('status', TimeEntryStatus::Approved->value)
            ->whereNull('invoice_line_id')
            ->where('is_legacy', false)
            ->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->when($frequency, fn ($q) => $q->whereHas('client', fn ($c) => $c->where('invoice_frequency', $frequency)))
            ->orderBy('started_at')
            ->get();
    }

    /**
     * One line per visit.
     *
     * @return Collection<int, array{appointment: Appointment, date: Carbon, description: string, quantity: float, unit: ?float, amount: float, entry_ids: int[]}>
     */
    private function visits(Collection $entries): Collection
    {
        return $entries->groupBy('appointment_id')->map(function (Collection $visitEntries) {
            $first = $visitEntries->first();
            $appointment = $first->appointment;
            $client = $first->client;
            $hourly = ($appointment->pricing_model ?? $client->pricing_model) === PricingModel::Hourly;
            $rate = $first->charge_rate ?? $appointment->price ?? $client->currentPrice();
            $minutes = (int) $visitEntries->sum('billable_minutes');
            $quantity = $hourly ? round($minutes / 60, 4) : 1.0;
            $unit = $rate === null ? null : round((float) $rate, 2);
            $date = $appointment->localStart();
            $service = $appointment->serviceType?->name_en ?? $client->serviceType?->name_en ?? 'Cleaning service';
            $cleaners = $visitEntries->map(fn ($e) => Str::before($e->user->name, ' ') ?: $e->user->name)->unique()->implode(', ');
            $hours = rtrim(rtrim(number_format($minutes / 60, 2), '0'), '.');

            return [
                'appointment' => $appointment,
                'date' => $date,
                'description' => "{$service} – {$date->format('D j M Y')} – {$cleaners} ({$hours} h)",
                'quantity' => $quantity,
                'unit' => $unit,
                'amount' => $unit === null ? 0.0 : round($quantity * $unit, 2),
                'entry_ids' => $visitEntries->pluck('id')->all(),
            ];
        })->sortBy(fn ($v) => $v['date'])->values();
    }
}
