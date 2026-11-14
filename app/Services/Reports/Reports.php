<?php

namespace App\Services\Reports;

use App\Models\Cancellation;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\SmsMessage;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every report returns the same shape so one view and one CSV exporter handle them all:
 *   ['columns' => [key => [label, format]], 'rows' => [[key => value]], 'totals' => [key => value], 'notes' => string[]]
 * Formats: text · int · money · hours (value in minutes) · percent · signed_minutes
 */
class Reports
{
    public const KEYS = ['revenue', 'payroll', 'margin', 'variance', 'cancellations', 'sms', 'invoices'];

    public function __construct(private VisitLedger $ledger) {}

    public function run(string $key, Carbon $from, Carbon $to, ?int $clientId = null, ?int $staffId = null, bool $detail = false): array
    {
        return match ($key) {
            'revenue' => $this->revenue($from, $to, $clientId),
            'payroll' => $detail ? $this->payrollDetail($from, $to, $staffId) : $this->payroll($from, $to, $staffId),
            'margin' => $this->margin($from, $to, $clientId),
            'variance' => $this->variance($from, $to, $clientId),
            'cancellations' => $this->cancellations($from, $to, $clientId),
            'sms' => $this->sms($from, $to),
            'invoices' => $this->invoices($from, $to, $clientId),
        };
    }

    private function col(string $key, string $format = 'text'): array
    {
        return [__('reports.cols.'.$key), $format];
    }

    private function revenue(Carbon $from, Carbon $to, ?int $clientId): array
    {
        $visits = $this->ledger->visits($from, $to, $clientId);
        $fees = $this->cancellationFees($from, $to, $clientId);

        $rows = $visits->groupBy('client_id')->map(function (Collection $v) use ($fees) {
            $revenue = round($v->sum('revenue'), 2);
            $fee = round((float) ($fees[$v->first()['client_id']] ?? 0), 2);
            $ex = round($revenue + $fee, 2);

            return ['client' => $v->first()['client'], 'visits' => $v->count(), 'billable' => $v->sum('billable'),
                'revenue' => $revenue, 'fees' => $fee, 'total_ex' => $ex, 'gst' => round($ex * 0.10, 2), 'total_inc' => round($ex * 1.10, 2),
                'avg' => $v->count() ? round($revenue / $v->count(), 2) : 0];
        });
        // Clients with only cancellation fees in the period.
        foreach ($fees as $cid => $fee) {
            if (! $rows->has($cid)) {
                $name = Client::withTrashed()->find($cid)?->display_name ?? '#'.$cid;
                $rows[$cid] = ['client' => $name, 'visits' => 0, 'billable' => 0, 'revenue' => 0.0, 'fees' => round($fee, 2),
                    'total_ex' => round($fee, 2), 'gst' => round($fee * 0.10, 2), 'total_inc' => round($fee * 1.10, 2), 'avg' => 0];
            }
        }
        $rows = $rows->sortByDesc('total_ex')->values();

        return [
            'columns' => ['client' => $this->col('client'), 'visits' => $this->col('visits', 'int'), 'billable' => $this->col('billable_hours', 'hours'),
                'revenue' => $this->col('cleaning', 'money'), 'fees' => $this->col('cancellation_fees', 'money'), 'total_ex' => $this->col('total_ex', 'money'),
                'gst' => $this->col('gst', 'money'), 'total_inc' => $this->col('total_inc', 'money'), 'avg' => $this->col('avg_visit', 'money')],
            'rows' => $rows->all(),
            'totals' => ['client' => __('reports.total'), 'visits' => $rows->sum('visits'), 'billable' => $rows->sum('billable'), 'revenue' => $rows->sum('revenue'),
                'fees' => $rows->sum('fees'), 'total_ex' => $rows->sum('total_ex'), 'gst' => $rows->sum('gst'), 'total_inc' => $rows->sum('total_inc'),
                'avg' => $rows->sum('visits') ? round($rows->sum('revenue') / $rows->sum('visits'), 2) : 0],
            'notes' => [__('reports.notes.confirmed_work')],
        ];
    }

    private function payroll(Carbon $from, Carbon $to, ?int $staffId): array
    {
        $lines = $this->ledger->visits($from, $to, null, $staffId)->flatMap(fn ($v) => $v['staff'])
            ->when($staffId, fn ($c) => $c->where('id', $staffId));

        $rows = $lines->groupBy('id')->map(function (Collection $l) {
            return ['staff' => $l->first()['name'],
                'weekday' => $l->where('day', '<', 6)->sum('minutes'), 'saturday' => $l->where('day', 6)->sum('minutes'), 'sunday' => $l->where('day', 7)->sum('minutes'),
                'total_minutes' => $l->sum('minutes'), 'pay' => round($l->sum('pay'), 2), 'oncost' => round($l->sum('oncost'), 2),
                'cost' => round($l->sum('pay') + $l->sum('oncost'), 2)];
        })->sortBy('staff')->values();

        return [
            'columns' => ['staff' => $this->col('staff'), 'weekday' => $this->col('weekday', 'hours'), 'saturday' => $this->col('saturday', 'hours'),
                'sunday' => $this->col('sunday', 'hours'), 'total_minutes' => $this->col('total_hours', 'hours'), 'pay' => $this->col('gross_pay', 'money'),
                'oncost' => $this->col('oncost', 'money'), 'cost' => $this->col('labour_cost', 'money')],
            'rows' => $rows->all(),
            'totals' => ['staff' => __('reports.total')] + collect(['weekday', 'saturday', 'sunday', 'total_minutes', 'pay', 'oncost', 'cost'])->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all(),
            'notes' => [__('reports.notes.payroll'), __('reports.notes.oncost', ['pct' => app(Settings::class)->get('labour_on_cost_percent', 12)])],
        ];
    }

    /** One line per shift — the payroll export. */
    private function payrollDetail(Carbon $from, Carbon $to, ?int $staffId): array
    {
        $rows = $this->ledger->visits($from, $to, null, $staffId)->flatMap(function ($v) use ($staffId) {
            return collect($v['staff'])->when($staffId, fn ($c) => $c->where('id', $staffId))->map(function ($s) use ($v) {
                $e = $s['entry'];

                return ['date' => $v['date']->format('Y-m-d'), 'staff' => $s['name'], 'client' => $v['client'],
                    'start' => $e->localStart()->format('H:i'), 'end' => $e->localEnd()?->format('H:i'), 'total_minutes' => $s['minutes'],
                    'rate' => (float) ($e->pay_rate ?? $e->user->payRateFor($e->localStart())), 'pay' => $s['pay']];
            });
        })->sortBy(fn ($r) => $r['staff'].$r['date'].$r['start'])->values();

        return [
            'columns' => ['date' => $this->col('date'), 'staff' => $this->col('staff'), 'client' => $this->col('client'), 'start' => $this->col('start'),
                'end' => $this->col('end'), 'total_minutes' => $this->col('total_hours', 'hours'), 'rate' => $this->col('rate', 'money'), 'pay' => $this->col('gross_pay', 'money')],
            'rows' => $rows->all(),
            'totals' => ['date' => __('reports.total'), 'total_minutes' => $rows->sum('total_minutes'), 'pay' => $rows->sum('pay')],
            'notes' => [__('reports.notes.payroll')],
        ];
    }

    private function margin(Carbon $from, Carbon $to, ?int $clientId): array
    {
        $rows = $this->ledger->visits($from, $to, $clientId)->groupBy('client_id')->map(function (Collection $v) {
            $revenue = round($v->sum('revenue'), 2);
            $labour = round($v->sum('labour_with_oncost'), 2);
            $hours = $v->sum('logged') / 60;

            return ['client' => $v->first()['client'], 'visits' => $v->count(), 'revenue' => $revenue, 'labour' => $labour,
                'margin' => round($revenue - $labour, 2), 'margin_pct' => $revenue > 0 ? round(($revenue - $labour) / $revenue * 100, 1) : null,
                'per_hour' => $hours > 0 ? round($revenue / $hours, 2) : null];
        })->sortBy('margin_pct')->values();

        $rev = $rows->sum('revenue');
        $lab = $rows->sum('labour');

        return [
            'columns' => ['client' => $this->col('client'), 'visits' => $this->col('visits', 'int'), 'revenue' => $this->col('revenue', 'money'),
                'labour' => $this->col('labour_cost', 'money'), 'margin' => $this->col('margin', 'money'), 'margin_pct' => $this->col('margin_pct', 'percent'),
                'per_hour' => $this->col('revenue_per_hour', 'money')],
            'rows' => $rows->all(),
            'totals' => ['client' => __('reports.total'), 'visits' => $rows->sum('visits'), 'revenue' => $rev, 'labour' => $lab, 'margin' => round($rev - $lab, 2),
                'margin_pct' => $rev > 0 ? round(($rev - $lab) / $rev * 100, 1) : null, 'per_hour' => null],
            'notes' => [__('reports.notes.margin')],
        ];
    }

    private function variance(Carbon $from, Carbon $to, ?int $clientId): array
    {
        $rows = $this->ledger->visits($from, $to, $clientId)->groupBy('client_id')->map(function (Collection $v) {
            // Compare the visit's scheduled length with the longest cleaner's time (cleaners work side by side).
            $diffs = $v->map(fn ($x) => max(array_column($x['staff'], 'minutes') ?: [0]) - $x['scheduled']);

            return ['client' => $v->first()['client'], 'visits' => $v->count(), 'scheduled' => $v->sum('scheduled'),
                'actual' => $v->sum(fn ($x) => max(array_column($x['staff'], 'minutes') ?: [0])),
                'avg_diff' => (int) round($diffs->avg()), 'over_30' => $diffs->filter(fn ($d) => $d >= 30)->count()];
        })->sortByDesc('avg_diff')->values();

        return [
            'columns' => ['client' => $this->col('client'), 'visits' => $this->col('visits', 'int'), 'scheduled' => $this->col('scheduled_hours', 'hours'),
                'actual' => $this->col('actual_hours', 'hours'), 'avg_diff' => $this->col('avg_difference', 'signed_minutes'), 'over_30' => $this->col('over_30', 'int')],
            'rows' => $rows->all(),
            'totals' => ['client' => __('reports.total'), 'visits' => $rows->sum('visits'), 'scheduled' => $rows->sum('scheduled'), 'actual' => $rows->sum('actual'),
                'avg_diff' => $rows->sum('visits') ? (int) round(($rows->sum('actual') - $rows->sum('scheduled')) / $rows->sum('visits')) : 0, 'over_30' => $rows->sum('over_30')],
            'notes' => [__('reports.notes.variance')],
        ];
    }

    private function cancellations(Carbon $from, Carbon $to, ?int $clientId): array
    {
        $items = $this->cancellationQuery($from, $to, $clientId)->get();
        $rows = $items->groupBy('reason')->map(fn (Collection $c, $reason) => [
            'reason' => __('cancellations.reasons.'.$reason), 'count' => $c->count(), 'late' => $c->where('fee_eligible', true)->count(),
            'charged' => $c->where('fee_decision', 'charged')->count(), 'charged_amount' => round((float) $c->where('fee_decision', 'charged')->sum('fee_amount_ex_gst'), 2),
            'waived' => $c->where('fee_decision', 'waived')->count(),
        ])->sortByDesc('count')->values();

        return [
            'columns' => ['reason' => $this->col('reason'), 'count' => $this->col('cancellations', 'int'), 'late' => $this->col('late', 'int'),
                'charged' => $this->col('fees_charged', 'int'), 'charged_amount' => $this->col('fees_amount', 'money'), 'waived' => $this->col('fees_waived', 'int')],
            'rows' => $rows->all(),
            'totals' => ['reason' => __('reports.total')] + collect(['count', 'late', 'charged', 'charged_amount', 'waived'])->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all(),
            'notes' => [],
        ];
    }

    private function sms(Carbon $from, Carbon $to): array
    {
        $rows = SmsMessage::query()->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])->get()
            ->groupBy(fn ($m) => $m->created_at->tz(config('apq.display_timezone'))->format('Y-m'))
            ->map(fn (Collection $m, $month) => [
                'month' => Carbon::createFromFormat('Y-m', $month)->translatedFormat('F Y'),
                'sent' => $m->where('direction', 'outbound')->whereIn('status', ['sent', 'delivered'])->count(),
                'delivered' => $m->where('status', 'delivered')->count(), 'failed' => $m->where('status', 'failed')->count(),
                'skipped' => $m->where('status', 'skipped')->count(), 'replies' => $m->where('direction', 'inbound')->count(),
                'credits' => round((float) $m->sum('cost_credits'), 2),
            ])->sortKeys()->values();

        return [
            'columns' => ['month' => $this->col('month'), 'sent' => $this->col('sent', 'int'), 'delivered' => $this->col('delivered', 'int'),
                'failed' => $this->col('failed', 'int'), 'skipped' => $this->col('skipped', 'int'), 'replies' => $this->col('replies', 'int'), 'credits' => $this->col('credits', 'int')],
            'rows' => $rows->all(),
            'totals' => ['month' => __('reports.total')] + collect(['sent', 'delivered', 'failed', 'skipped', 'replies', 'credits'])->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all(),
            'notes' => [],
        ];
    }

    private function invoices(Carbon $from, Carbon $to, ?int $clientId): array
    {
        $invoices = Invoice::query()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))->get();
        $rows = $invoices->groupBy(fn ($i) => $i->stateKey())->map(fn (Collection $i, $state) => [
            'state' => __('invoices.states.'.$state), 'count' => $i->count(),
            'total' => round((float) $i->sum(fn ($x) => $x->xero_total ?? $x->total), 2),
            'due' => round((float) $i->where('xero_status', 'AUTHORISED')->sum('xero_amount_due'), 2),
        ])->values();

        return [
            'columns' => ['state' => $this->col('status'), 'count' => $this->col('invoices', 'int'), 'total' => $this->col('total_inc', 'money'), 'due' => $this->col('outstanding', 'money')],
            'rows' => $rows->all(),
            'totals' => ['state' => __('reports.total'), 'count' => $rows->sum('count'), 'total' => $rows->sum('total'), 'due' => $rows->sum('due')],
            'notes' => [__('reports.notes.invoices')],
        ];
    }

    /** @return array<int, float> client_id => charged fees ex GST, for visits in the period */
    private function cancellationFees(Carbon $from, Carbon $to, ?int $clientId): array
    {
        return $this->cancellationQuery($from, $to, $clientId)->where('fee_decision', 'charged')->get()
            ->groupBy(fn ($c) => $c->appointment->client_id)->map(fn ($c) => (float) $c->sum('fee_amount_ex_gst'))->all();
    }

    private function cancellationQuery(Carbon $from, Carbon $to, ?int $clientId)
    {
        return Cancellation::query()->with('appointment')
            ->whereHas('appointment', fn ($q) => $q->withTrashed()
                ->whereBetween('scheduled_start', [$from->copy()->utc(), $to->copy()->utc()])
                ->when($clientId, fn ($a) => $a->where('client_id', $clientId)));
    }
}
