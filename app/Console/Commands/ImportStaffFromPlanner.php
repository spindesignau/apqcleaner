<?php

namespace App\Console\Commands;

use App\Enums\EmploymentType;
use App\Enums\Role;
use App\Models\User;
use App\Services\Audit;
use App\Support\XlsxReader;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates staff accounts from the "EMPLEADOS" block of a Weekly Planner sheet
 * (column A = name, column E = hourly rate). See docs/01-SPECIFICATION.md §7.
 */
class ImportStaffFromPlanner extends Command
{
    protected $signature = 'apq:import:staff
        {file : Path to the Weekly Planner .xlsx}
        {--sheet= : Sheet name (default: the last dated weekly sheet)}
        {--email-domain=pending.apqcleaning.invalid : Placeholder email domain until real emails are entered}
        {--commit : Write to the database (otherwise dry run)}';

    protected $description = 'Import cleaning staff names and hourly rates from the Weekly Planner workbook';

    public function handle(): int
    {
        $book = new XlsxReader($this->argument('file'));
        $sheet = $this->option('sheet') ?? $this->latestWeeklySheet($book);
        $this->info("Reading sheet: {$sheet}");

        $people = $this->parseStaff($book->rows($sheet));
        if ($people === []) {
            $this->error('No staff rows found (expected an "Empleado" header row).');

            return self::FAILURE;
        }

        $report = [];
        foreach ($people as $p) {
            $existing = $this->findExisting($p['name']);
            $action = $existing ? 'update rate' : 'create';
            $email = $existing?->email ?? Str::slug($p['name'], '.').'@'.$this->option('email-domain');
            $tempPassword = $existing || ! $this->option('commit') ? '—' : Str::password(12, symbols: false);

            if ($this->option('commit')) {
                if ($existing) {
                    $existing->forceFill(['hourly_rate' => $p['rate']])->save();
                    Audit::log('user.updated', $existing, after: ['hourly_rate' => $p['rate']], reason: 'Imported from Weekly Planner');
                } else {
                    $user = new User;
                    $user->forceFill([
                        'name' => $p['name'],
                        'email' => $email,
                        'password' => $tempPassword,
                        'role' => Role::Staff,
                        'employment_type' => EmploymentType::Casual,
                        'hourly_rate' => $p['rate'],
                        'locale' => 'es',
                        'must_change_password' => true,
                        'is_active' => true,
                    ])->save();
                    Audit::log('user.created', $user, reason: 'Imported from Weekly Planner');
                }
            }

            $report[] = [$p['name'], number_format($p['rate'], 2), $action, $email, $tempPassword];
        }

        $this->table(['Name', 'Rate/h', 'Action', 'Login email', 'Temp password'], $report);
        $this->line($this->option('commit')
            ? '<info>Imported.</info> Replace placeholder emails in Admin → Staff, then share the temporary passwords.'
            : '<comment>Dry run — nothing saved. Re-run with --commit.</comment>');

        return self::SUCCESS;
    }

    /** @return array<int,array{name:string,rate:float}> */
    private function parseStaff(array $rows): array
    {
        $out = [];
        $inBlock = false;
        foreach ($rows as $cells) {
            $a = trim((string) ($cells['A'] ?? ''));
            if (! $inBlock) {
                $inBlock = Str::lower($a) === 'empleado';

                continue;
            }
            // The first day's block ends at its "Total" row.
            if ($a === '' || Str::startsWith(Str::lower($a), 'total')) {
                break;
            }
            $rate = $cells['E'] ?? null;
            if (is_numeric($rate) && $rate > 0) {
                $out[] = ['name' => Str::title(preg_replace('/\s+/', ' ', $a)), 'rate' => (float) $rate];
            }
        }

        return $out;
    }

    private function findExisting(string $name): ?User
    {
        $exact = User::whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();
        if ($exact) {
            return $exact;
        }
        // Single-word names in the planner ("Adriana") match a unique first name.
        if (! str_contains($name, ' ')) {
            $matches = User::where('name', 'like', $name.' %')->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return null;
    }

    private function latestWeeklySheet(XlsxReader $book): string
    {
        $weekly = array_values(array_filter($book->sheetNames(), fn ($n) => preg_match('/\d/', $n)));

        return end($weekly) ?: $book->sheetNames()[0];
    }
}
