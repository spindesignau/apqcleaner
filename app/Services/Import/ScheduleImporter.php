<?php

namespace App\Services\Import;

use App\Enums\AppointmentStatus;
use App\Enums\Frequency;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\RecurringSchedule;
use App\Models\User;
use App\Services\Audit;
use App\Services\Scheduling\AppointmentGenerator;
use App\Services\Scheduling\ConflictFinder;
use App\Support\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads a cleaner schedule spreadsheet (CSV or .xlsx, see TEMPLATE_HEADERS) into a reviewable plan,
 * then creates one-off visits and regular schedules from the valid rows.
 */
class ScheduleImporter
{
    public const TEMPLATE_HEADERS = ['Cleaner', 'Client', 'Date', 'Start', 'End', 'Repeat', 'Notes'];

    private const REPEAT = [
        '' => null, 'one-off' => null, 'one off' => null, 'once' => null, 'no' => null, 'unica' => null, 'única' => null,
        'weekly' => Frequency::Weekly, 'semanal' => Frequency::Weekly, 'every week' => Frequency::Weekly,
        'fortnightly' => Frequency::Fortnightly, 'biweekly' => Frequency::Fortnightly, 'every 2 weeks' => Frequency::Fortnightly, 'quincenal' => Frequency::Fortnightly,
        'every 3 weeks' => Frequency::Every3Weeks, 'cada 3 semanas' => Frequency::Every3Weeks,
        'every 4 weeks' => Frequency::Every4Weeks, 'cada 4 semanas' => Frequency::Every4Weeks,
        'monthly' => Frequency::MonthlyNthWeekday, 'mensual' => Frequency::MonthlyNthWeekday,
    ];

    public function __construct(private ConflictFinder $conflicts) {}

    /**
     * @return array{rows: array<int, array>, counts: array<string,int>}|null null = header row not found
     */
    public function plan(string $path, string $extension, ?int $defaultCleanerId = null): ?array
    {
        $raw = $this->read($path, $extension);
        if ($raw === null) {
            return null;
        }

        $tz = config('apq.display_timezone');
        $clients = Client::query()->where('is_active', true)->get(['id', 'display_name', 'billing_name']);
        $staff = User::query()->where('is_active', true)->get(['id', 'name', 'email']);
        $default = $defaultCleanerId ? $staff->firstWhere('id', $defaultCleanerId) : null;
        $rows = [];
        $counts = ['visits' => 0, 'schedules' => 0, 'errors' => 0, 'skipped' => 0, 'warnings' => 0];
        $seen = [];

        foreach ($raw as $line => $r) {
            if (collect($r)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                continue;
            }
            $errors = [];
            $warnings = [];

            $client = $this->matchClient(trim((string) ($r['client'] ?? '')), $clients, $errors, $warnings);
            $cleaners = $this->matchCleaners(trim((string) ($r['cleaner'] ?? '')), $staff, $default, $errors, $warnings);
            $date = $this->parseDate($r['date'] ?? null);
            $start = $this->parseTime($r['start'] ?? null);
            $end = $this->parseTime($r['end'] ?? null);
            $repeatKey = Str::lower(trim((string) ($r['repeat'] ?? '')));
            $frequency = array_key_exists($repeatKey, self::REPEAT) ? self::REPEAT[$repeatKey] : false;

            if (! $date) {
                $errors[] = __('schedule_import.bad_date', ['value' => (string) ($r['date'] ?? '')]);
            }
            if (! $start) {
                $errors[] = __('schedule_import.bad_time', ['value' => (string) ($r['start'] ?? '')]);
            }
            if (! $end) {
                $errors[] = __('schedule_import.bad_time', ['value' => (string) ($r['end'] ?? '')]);
            }
            if ($frequency === false) {
                $errors[] = __('schedule_import.bad_repeat', ['value' => (string) $r['repeat']]);
                $frequency = null;
            }

            $startsAt = $endsAt = null;
            if ($date && $start && $end) {
                $startsAt = CarbonImmutable::parse("{$date} {$start}", $tz);
                $endsAt = CarbonImmutable::parse("{$date} {$end}", $tz);
                if ($endsAt->lte($startsAt)) {
                    $errors[] = __('schedule_import.end_before_start');
                } elseif ($startsAt->diffInMinutes($endsAt) > 12 * 60) {
                    $errors[] = __('schedule_import.too_long');
                }
                if (! $frequency && $startsAt->isPast()) {
                    $warnings[] = __('schedule_import.in_past');
                }
            }

            $action = $frequency ? 'schedule' : 'visit';
            $note = null;
            if (! $errors && $client && $startsAt) {
                $dupKey = implode('|', [$client->id, $frequency?->value ?? 'once', $frequency ? $startsAt->isoWeekday().$start : $startsAt->toIso8601String()]);
                if (isset($seen[$dupKey])) {
                    $action = 'skip';
                    $note = __('schedule_import.dup_in_file', ['line' => $seen[$dupKey]]);
                } elseif ($this->alreadyScheduled($client->id, $frequency, $startsAt, $start)) {
                    $action = 'skip';
                    $note = __('schedule_import.already_scheduled');
                } else {
                    $seen[$dupKey] = $line;
                    $clashes = $this->conflicts->forSlot(array_column($cleaners, 'id'), $startsAt, $endsAt);
                    foreach ($clashes->unique(fn ($c) => $c['user']->id.'-'.$c['appointment']->id) as $c) {
                        $warnings[] = __('schedule_import.double_booking', ['name' => $c['user']->name, 'client' => $c['appointment']->client->display_name, 'time' => $c['appointment']->timeRange()]);
                    }
                }
            }
            if ($errors) {
                $action = 'error';
            }

            $counts[match ($action) {
                'visit' => 'visits', 'schedule' => 'schedules', 'error' => 'errors', default => 'skipped'
            }]++;
            $counts['warnings'] += ($warnings && $action !== 'error') ? 1 : 0;

            $rows[] = [
                'line' => $line,
                'action' => $action,
                'note' => $note,
                'errors' => $errors,
                'warnings' => $warnings,
                'client_id' => $client?->id,
                'client_name' => $client?->display_name ?? (string) ($r['client'] ?? ''),
                'cleaners' => $cleaners,
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'frequency' => $frequency?->value,
                'notes' => trim((string) ($r['notes'] ?? '')) ?: null,
            ];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** @return array{visits: int, schedules: int, generated: int} */
    public function commit(array $plan, User $by, AppointmentGenerator $generator): array
    {
        return DB::transaction(function () use ($plan, $by, $generator) {
            $tz = config('apq.display_timezone');
            $result = ['visits' => 0, 'schedules' => 0, 'generated' => 0];

            foreach ($plan['rows'] as $row) {
                if (! in_array($row['action'], ['visit', 'schedule'], true)) {
                    continue;
                }
                $client = Client::find($row['client_id']);
                $staffIds = array_column($row['cleaners'], 'id');
                $start = CarbonImmutable::parse("{$row['date']} {$row['start']}", $tz);
                $end = CarbonImmutable::parse("{$row['date']} {$row['end']}", $tz);

                if ($row['action'] === 'visit') {
                    $visit = Appointment::create([
                        'client_id' => $client->id, 'service_type_id' => $client->service_type_id,
                        'scheduled_start' => $start, 'scheduled_end' => $end, 'status' => AppointmentStatus::Scheduled,
                        'job_notes' => $row['notes'] ?? $client->default_job_notes,
                        'pricing_model' => $client->pricing_model, 'price' => $client->currentPrice(), 'created_by' => $by->id,
                    ]);
                    $visit->staff()->sync($staffIds);
                    Audit::log('appointment.created', $visit, reason: 'Schedule upload');
                    $result['visits']++;

                    continue;
                }

                $frequency = Frequency::from($row['frequency']);
                $schedule = RecurringSchedule::create([
                    'client_id' => $client->id, 'service_type_id' => $client->service_type_id,
                    'frequency' => $frequency, 'weekday' => $start->isoWeekday(),
                    'nth_week' => $frequency === Frequency::MonthlyNthWeekday ? ($start->day > 28 ? -1 : (int) ceil($start->day / 7)) : null,
                    'start_time' => $row['start'], 'duration_minutes' => (int) $start->diffInMinutes($end),
                    'anchor_date' => $start->toDateString(), 'default_job_notes' => $row['notes'] ?? $client->default_job_notes,
                    'is_active' => true, 'created_by' => $by->id,
                ]);
                $schedule->staff()->sync($staffIds);
                $result['generated'] += $generator->generate($schedule->load('client'));
                Audit::log('schedule.created', $schedule, reason: 'Schedule upload');
                $result['schedules']++;
            }

            return $result;
        });
    }

    /** Header names → canonical keys; accepts English or Spanish headers in any order. */
    private function read(string $path, string $extension): ?array
    {
        $grid = $extension === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path);
        $aliases = [
            'cleaner' => ['cleaner', 'cleaners', 'staff', 'empleado', 'personal', 'limpiadora', 'limpiador'],
            'client' => ['client', 'cliente', 'customer'],
            'date' => ['date', 'fecha'],
            'start' => ['start', 'start time', 'inicio', 'hora inicio'],
            'end' => ['end', 'end time', 'fin', 'hora fin', 'finish'],
            'repeat' => ['repeat', 'repite', 'frequency', 'frecuencia', 'how often'],
            'notes' => ['notes', 'notas', 'comments'],
        ];

        $map = null;
        $rows = [];
        foreach ($grid as $line => $cells) {
            if ($map === null) {
                $candidate = [];
                foreach ($cells as $col => $value) {
                    $h = Str::lower(trim((string) $value));
                    foreach ($aliases as $key => $names) {
                        if (in_array($h, $names, true)) {
                            $candidate[$key] = $col;
                        }
                    }
                }
                if (isset($candidate['client'], $candidate['date'], $candidate['start'])) {
                    $map = $candidate;
                }

                continue;
            }
            $row = [];
            foreach ($aliases as $key => $_) {
                $row[$key] = isset($map[$key]) ? ($cells[$map[$key]] ?? '') : '';
            }
            $rows[$line] = $row;
        }

        return $map ? $rows : null;
    }

    /** @return array<int, array<int|string, mixed>> */
    private function readCsv(string $path): array
    {
        $fh = @fopen($path, 'r');
        if (! $fh) {
            return [];
        }
        $grid = [];
        $line = 0;
        while (($cols = fgetcsv($fh, escape: '')) !== false) {
            $line++;
            $grid[$line] = array_map(fn ($c) => preg_replace('/^\xEF\xBB\xBF/', '', (string) $c), $cols);
        }
        fclose($fh);

        return $grid;
    }

    private function readXlsx(string $path): array
    {
        try {
            $book = new XlsxReader($path);
        } catch (\Throwable) {
            return [];
        }

        return $book->rows($book->sheetNames()[0]);
    }

    private function matchClient(string $name, Collection $clients, array &$errors, array &$warnings): ?Client
    {
        if ($name === '') {
            $errors[] = __('schedule_import.no_client');

            return null;
        }
        $key = Str::lower($name);
        $exact = $clients->filter(fn ($c) => Str::lower($c->display_name) === $key || Str::lower($c->billing_name) === $key);
        if ($exact->count() === 1) {
            return $exact->first();
        }
        $partial = $clients->filter(fn ($c) => Str::contains(Str::lower($c->display_name), $key));
        if ($exact->isEmpty() && $partial->count() === 1) {
            $warnings[] = __('schedule_import.client_guess', ['value' => $name, 'name' => $partial->first()->display_name]);

            return $partial->first();
        }
        $errors[] = ($exact->count() + $partial->count()) > 1
            ? __('schedule_import.client_ambiguous', ['value' => $name])
            : __('schedule_import.client_unknown', ['value' => $name]);

        return null;
    }

    /** @return array<int, array{id:int, name:string}> */
    private function matchCleaners(string $value, Collection $staff, ?User $default, array &$errors, array &$warnings): array
    {
        if ($value === '') {
            if ($default) {
                return [['id' => $default->id, 'name' => $default->name]];
            }
            $warnings[] = __('schedule_import.no_cleaner');

            return [];
        }

        $found = [];
        foreach (preg_split('/\s*(?:;|&|\+|,|\/| and | y )\s*/i', $value) as $part) {
            $key = Str::lower(trim($part));
            if ($key === '') {
                continue;
            }
            $match = $staff->filter(fn ($u) => Str::lower($u->email) === $key || Str::lower($u->name) === $key);
            if ($match->isEmpty()) {
                $match = $staff->filter(fn ($u) => Str::lower(Str::before($u->name, ' ')) === $key);   // first name only
            }
            if ($match->count() === 1) {
                $found[$match->first()->id] = ['id' => $match->first()->id, 'name' => $match->first()->name];
            } else {
                $errors[] = $match->count() > 1
                    ? __('schedule_import.cleaner_ambiguous', ['value' => trim($part)])
                    : __('schedule_import.cleaner_unknown', ['value' => trim($part)]);
            }
        }

        return array_values($found);
    }

    private function alreadyScheduled(int $clientId, ?Frequency $frequency, CarbonImmutable $start, string $startTime): bool
    {
        if ($frequency) {
            return RecurringSchedule::where('client_id', $clientId)->where('is_active', true)
                ->where('weekday', $start->isoWeekday())->where('frequency', $frequency->value)
                ->where('start_time', 'like', $startTime.'%')->exists();
        }

        return Appointment::where('client_id', $clientId)->active()
            ->where('scheduled_start', $start->utc()->format('Y-m-d H:i:s'))->exists();
    }

    /** Y-m-d, d/m/Y, d/m/y, d-m-Y or an Excel date serial → Y-m-d. US month-first dates are not accepted. */
    private function parseDate(mixed $value): ?string
    {
        if (is_float($value) || is_int($value) || (is_string($value) && preg_match('/^\d{5}(\.\d+)?$/', trim($value)))) {
            $serial = (int) floor((float) $value);

            return $serial > 30000 && $serial < 80000 ? CarbonImmutable::create(1899, 12, 30)->addDays($serial)->toDateString() : null;
        }
        $v = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $y += $y < 100 ? 2000 : 0;
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** "9:00", "09:00", "9am", "9.30", "2:30 pm", "14:30" or an Excel time fraction → H:i. */
    private function parseTime(mixed $value): ?string
    {
        if (is_float($value) || is_int($value)) {
            $fraction = fmod((float) $value, 1.0);
            $minutes = (int) round($fraction * 1440);

            return $value >= 0 && $minutes < 1440 ? sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60) : null;
        }
        $v = Str::lower(str_replace(' ', '', trim((string) $value)));
        if (! preg_match('/^(\d{1,2})(?:[:.](\d{2}))?(am|pm)?$/', $v, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);
        $ampm = $m[3] ?? null;
        if ($ampm === 'pm' && $h < 12) {
            $h += 12;
        }
        if ($ampm === 'am' && $h === 12) {
            $h = 0;
        }

        return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : null;
    }
}
