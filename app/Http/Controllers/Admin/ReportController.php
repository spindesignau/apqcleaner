<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Reports\Reports;
use App\Support\PayPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('admin.reports.index', ['keys' => Reports::KEYS]);
    }

    public function show(Request $request, string $report, Reports $reports): View|StreamedResponse
    {
        abort_unless(in_array($report, Reports::KEYS, true), 404);
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'client' => ['nullable', 'integer'], 'staff' => ['nullable', 'integer'], 'format' => ['nullable', Rule::in(['csv'])],
        ]);

        [$from, $to] = $this->period($request, $report);
        $detail = $report === 'payroll' && $request->boolean('detail');
        $data = $reports->run($report, $from, $to, $request->integer('client') ?: null, $request->integer('staff') ?: null, $detail);

        if ($request->query('format') === 'csv') {
            return $this->csv($data, sprintf('apq-%s%s-%s-to-%s.csv', $report, $detail ? '-detail' : '', $from->toDateString(), $to->toDateString()));
        }

        return view('admin.reports.show', [
            'report' => $report, 'data' => $data, 'from' => $from, 'to' => $to, 'detail' => $detail,
            'clientId' => $request->integer('client') ?: null, 'staffId' => $request->integer('staff') ?: null,
            'clients' => in_array($report, ['revenue', 'margin', 'variance', 'cancellations', 'invoices'], true) ? Client::withTrashed()->orderBy('display_name')->get(['id', 'display_name']) : collect(),
            'staffOptions' => $report === 'payroll' ? User::orderBy('name')->get(['id', 'name']) : collect(),
            'presets' => $this->presets($report),
        ]);
    }

    /** Payroll defaults to the current pay period; everything else to last month. */
    private function period(Request $request, string $report): array
    {
        $tz = config('apq.display_timezone');
        if ($request->filled('from') && $request->filled('to')) {
            return [Carbon::parse($request->input('from'), $tz)->startOfDay(), Carbon::parse($request->input('to'), $tz)->endOfDay()];
        }
        if ($report === 'payroll') {
            $p = PayPeriod::containing();

            return [$p->start, $p->end];
        }
        $m = Carbon::now($tz)->subMonthNoOverflow();

        return [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()];
    }

    private function presets(string $report): array
    {
        $tz = config('apq.display_timezone');
        $now = Carbon::now($tz);
        $pay = PayPeriod::containing();
        $prevPay = PayPeriod::containing($pay->start->copy()->subDay());
        $fyStart = $now->month >= 7 ? $now->copy()->month(7)->startOfMonth() : $now->copy()->subYear()->month(7)->startOfMonth();

        return array_filter([
            'this_pay' => $report === 'payroll' ? [$pay->start, $pay->end] : null,
            'last_pay' => $report === 'payroll' ? [$prevPay->start, $prevPay->end] : null,
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_fy' => [$fyStart, $fyStart->copy()->addYear()->subDay()],   // Australian financial year (1 Jul – 30 Jun)
        ]);
    }

    private function csv(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => $c[0], $data['columns']), escape: '');
            foreach (array_merge($data['rows'], $data['totals'] ? [$data['totals']] : []) as $row) {
                fputcsv($out, array_map(fn ($key) => self::csvValue($row[$key] ?? '', $data['columns'][$key][1]), array_keys($data['columns'])), escape: '');
            }
            fclose($out);
        }, Str::slug(pathinfo($filename, PATHINFO_FILENAME)).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** CSV keeps plain numbers (hours as decimals) so spreadsheets can total them. */
    public static function csvValue(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($format) {
            'money' => number_format((float) $value, 2, '.', ''),
            'hours' => number_format((float) $value / 60, 2, '.', ''),
            'percent' => number_format((float) $value, 1, '.', ''),
            default => (string) $value,
        };
    }
}
