<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Import\ScheduleImporter;
use App\Services\Scheduling\AppointmentGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Schedule → Upload schedule: download the template, upload CSV/XLSX, preview, confirm.
 */
class ScheduleImportController extends Controller
{
    private const SESSION = 'schedule_import';

    public function template(): StreamedResponse
    {
        $tz = config('apq.display_timezone');
        $monday = Carbon::now($tz)->next(Carbon::MONDAY);
        $rows = [
            ScheduleImporter::TEMPLATE_HEADERS,
            ['rosa@example.com', 'Smith – Manly', $monday->format('d/m/Y'), '9:00', '12:00', 'Fortnightly', 'EXAMPLE – replace these rows with your own'],
            ['Rosa', 'Jones – Dee Why', $monday->copy()->addDay()->format('d/m/Y'), '1:00pm', '3:30pm', '', 'One-off visit: leave Repeat blank'],
            ['Rosa; Ana', 'Brown – Mona Vale', $monday->copy()->addDays(2)->format('d/m/Y'), '8:30', '13:30', 'Weekly', 'Two cleaners: separate names with ;'],
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows "–" and accents correctly
            foreach ($rows as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, 'apq-schedule-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Request $request): View
    {
        return view('admin.schedule.import', [
            'staffOptions' => User::assignable()->get(['id', 'name']),
            'selectedCleaner' => $request->integer('cleaner') ?: null,
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:4096'],
            'cleaner' => ['nullable', 'integer', 'exists:users,id'],
        ], attributes: ['file' => __('schedule_import.file')]);

        $this->discard($request);
        $ext = Str::lower($request->file('file')->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $path = $request->file('file')->storeAs('imports', Str::uuid().'.'.$ext, 'local');
        $request->session()->put(self::SESSION, [
            'path' => $path, 'ext' => $ext, 'cleaner' => $data['cleaner'] ?? null, 'name' => $request->file('file')->getClientOriginalName(),
        ]);

        return redirect()->route('admin.schedule.import.preview');
    }

    public function preview(Request $request, ScheduleImporter $importer): View|RedirectResponse
    {
        if (! $state = $this->state($request)) {
            return $this->expired();
        }
        $plan = $importer->plan(Storage::disk('local')->path($state['path']), $state['ext'], $state['cleaner']);
        if ($plan === null) {
            $this->discard($request);

            return redirect()->route('admin.schedule.import')->withErrors(['file' => __('schedule_import.bad_file')]);
        }

        return view('admin.schedule.import-preview', [
            'plan' => $plan, 'state' => $state,
            'defaultCleaner' => $state['cleaner'] ? User::find($state['cleaner'])?->name : null,
        ]);
    }

    public function confirm(Request $request, ScheduleImporter $importer, AppointmentGenerator $generator): RedirectResponse
    {
        if (! $state = $this->state($request)) {
            return $this->expired();
        }
        $plan = $importer->plan(Storage::disk('local')->path($state['path']), $state['ext'], $state['cleaner']);
        abort_if($plan === null, 422);

        $result = $importer->commit($plan, $request->user(), $generator);
        $this->discard($request);
        $firstDate = collect($plan['rows'])->whereIn('action', ['visit', 'schedule'])->pluck('date')->filter()->min();

        return redirect()->route('admin.schedule', $firstDate ? ['week' => Carbon::parse($firstDate)->format('o-\WW')] : [])
            ->with('status', __('schedule_import.done', $result));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->discard($request);

        return redirect()->route('admin.schedule');
    }

    private function state(Request $request): ?array
    {
        $state = $request->session()->get(self::SESSION);

        return $state && Storage::disk('local')->exists($state['path']) ? $state : null;
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('admin.schedule.import')->withErrors(['file' => __('schedule_import.expired')]);
    }

    private function discard(Request $request): void
    {
        if ($state = $request->session()->pull(self::SESSION)) {
            Storage::disk('local')->delete($state['path']);
        }
    }
}
