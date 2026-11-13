<?php

namespace App\Console\Commands;

use App\Enums\PaymentMethod;
use App\Enums\PricingModel;
use App\Models\Client;
use App\Models\ServiceType;
use App\Services\Audit;
use App\Support\AuPhone;
use App\Support\Suburbs;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports the "Datos" client sheet (CSV export). See docs/01-SPECIFICATION.md §7.
 * Rows whose STATE is "Cancelled" are skipped unless --include-cancelled is given.
 */
class ImportClients extends Command
{
    protected $signature = 'apq:import:clients
        {file : Path to the client CSV (Datos sheet)}
        {--include-cancelled : Also import rows marked "Cancelled" (imported as inactive)}
        {--commit : Write to the database (otherwise dry run)}';

    protected $description = 'Import clients from the client database CSV';

    private const FREQUENCIES = [
        'weekly' => 'Weekly', 'biweekly' => 'Fortnightly', 'every 3 weeks' => 'Every 3 weeks',
        'cada 3 semanas' => 'Every 3 weeks', 'cada 4 semanas' => 'Every 4 weeks', 'monthly' => 'Monthly',
        'mensual' => 'Monthly', 'twice a week' => 'Twice a week', 'sporadic' => 'Sporadic (one-off visits)',
        'airbnb' => 'Airbnb (as booked)', 'final obra' => 'End of work (one-off)',
    ];

    private const SERVICE_TYPES = [
        'general service' => 'General Service', 'first general service' => 'First General Service',
        'end of work' => 'End of Lease / End of Work', 'housekeeping' => 'Housekeeping',
    ];

    public function handle(): int
    {
        $rows = $this->readCsv($this->argument('file'));
        if ($rows === null) {
            $this->error('Could not find the header row (expected a "CUSTOMER" column).');

            return self::FAILURE;
        }

        $serviceTypes = ServiceType::pluck('id', 'name_en');
        $seen = ['mobile' => [], 'email' => [], 'name' => []];
        $counts = ['create' => 0, 'cancelled' => 0, 'duplicate' => 0, 'empty' => 0];
        $report = [];
        $warnings = [];

        foreach ($rows as $line => $r) {
            $name = $this->clean($r['CUSTOMER'] ?? '');
            if ($name === '') {
                $counts['empty']++;

                continue;
            }
            $cancelled = Str::lower($this->clean($r['STATE'] ?? '')) === 'cancelled';
            if ($cancelled && ! $this->option('include-cancelled')) {
                $counts['cancelled']++;

                continue;
            }

            $data = $this->map($r, $name, $serviceTypes, $warnings, $line);
            $data['is_active'] = ! $cancelled;

            if ($dup = $this->duplicateOf($data, $seen)) {
                $counts['duplicate']++;
                $report[] = [$data['display_name'], $data['suburb'] ?? '—', AuPhone::display($data['mobile']) ?? '—', "skip: same {$dup}"];

                continue;
            }
            foreach (['mobile', 'email'] as $k) {
                if ($data[$k]) {
                    $seen[$k][] = Str::lower($data[$k]);
                }
            }
            $seen['name'][] = Str::lower($data['display_name']);

            if ($this->option('commit')) {
                $client = Client::create($data);
                Audit::log('client.imported', $client, reason: 'Imported from client spreadsheet');
            }
            $counts['create']++;
            $report[] = [$data['display_name'], $data['suburb'] ?? '—', AuPhone::display($data['mobile']) ?? '—', $cancelled ? 'create (inactive)' : 'create'];
        }

        $this->table(['Client', 'Suburb', 'Phone', 'Action'], $report);
        foreach ($warnings as $w) {
            $this->line("<comment>⚠ {$w}</comment>");
        }
        $this->newLine();
        $this->line("To create: <info>{$counts['create']}</info> · Skipped as cancelled: <comment>{$counts['cancelled']}</comment> · Duplicates: {$counts['duplicate']}");
        if ($counts['cancelled'] > 0 && ! $this->option('include-cancelled')) {
            $this->line('<comment>Rows marked "Cancelled" were skipped. Use --include-cancelled to bring them in as inactive clients.</comment>');
        }
        $this->line($this->option('commit') ? '<info>Imported.</info> Set each client\'s price and regular schedule in Admin → Clients.' : '<comment>Dry run — nothing saved. Re-run with --commit.</comment>');

        return self::SUCCESS;
    }

    private function map(array $r, string $name, $serviceTypes, array &$warnings, int $line): array
    {
        $serviceAddress = $this->clean($r['SERVICE ADDRESS'] ?? '') ?: $this->clean($r['ADDRESS'] ?? '');
        $billingAddress = $this->clean($r['ADDRESS'] ?? '');
        [$street, $suburb, $postcode] = Suburbs::split($serviceAddress);

        $phones = array_values(array_filter(array_map('trim', preg_split('/\s[-\/]\s|,|;/', (string) ($r['PHONE'] ?? '')))));
        $mobile = AuPhone::normalise($phones[0] ?? null);
        $alt = isset($phones[1]) ? AuPhone::normalise($phones[1]) : null;

        $adminNotes = [];
        $rawEmail = Str::lower($this->clean($r['EMAIL'] ?? ''));
        $emails = array_values(array_filter(preg_split('/[\s,;\/]+/', $rawEmail), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $email = $emails[0] ?? null;
        if (count($emails) > 1) {
            $adminNotes[] = 'Other emails: '.implode(', ', array_slice($emails, 1));
        }
        if ($rawEmail !== '' && $email === null) {
            $adminNotes[] = "Email in spreadsheet looked invalid: {$rawEmail}";
            $warnings[] = "Line {$line} ({$name}): invalid email \"{$rawEmail}\" kept in admin notes";
        }
        if ($gift = $this->clean($r['GIFTS'] ?? '')) {
            $adminNotes[] = "Gifts: {$gift}";
        }
        if ($mobile && ! str_starts_with($mobile, '+')) {
            $warnings[] = "Line {$line} ({$name}): phone \"{$mobile}\" could not be normalised";
        }

        $freqRaw = Str::lower($this->clean($r['HOW OFTEN'] ?? ''));
        $typeRaw = Str::lower($this->clean($r['TYPE CLEANING SERVICE'] ?? ''));
        $display = Str::title(Str::lower($name));

        return [
            'display_name' => Str::limit($display, 150, ''),
            'billing_name' => Str::limit($display, 150, ''),
            'email' => $email,
            'mobile' => $mobile,
            'phone_alt' => $alt,
            'address_line1' => $street ?: '(address missing)',
            'suburb' => $suburb,
            'postcode' => $postcode,
            'state' => 'NSW',
            'billing_address' => $billingAddress && $billingAddress !== $serviceAddress ? $billingAddress : null,
            'access_notes' => $this->clean($r['METHOD OF ENTRY'] ?? '') ?: null,
            'default_job_notes' => $this->clean($r['FOCUS ON:'] ?? '') ?: null,
            'admin_notes' => $adminNotes ? implode("\n", $adminNotes) : null,
            'service_type_id' => $serviceTypes[self::SERVICE_TYPES[$typeRaw] ?? 'General Service'] ?? null,
            'pricing_model' => PricingModel::Fixed,
            'payment_method' => Str::contains(Str::lower($r['TYPE OF PAYMENT'] ?? ''), 'abn') ? PaymentMethod::Abn : PaymentMethod::Cash,
            'usual_frequency' => self::FREQUENCIES[$freqRaw] ?? ($freqRaw !== '' ? Str::title($freqRaw) : null),
            'referral_source' => $this->clean($r['HOW YOU CONTACT US'] ?? '') ?: null,
            'preferred_language' => 'en',
            'sms_opt_in' => true,
        ];
    }

    private function duplicateOf(array $data, array $seen): ?string
    {
        foreach (['mobile', 'email'] as $k) {
            if ($data[$k] && (in_array(Str::lower($data[$k]), $seen[$k], true) || Client::where($k, $data[$k])->exists())) {
                return $k;
            }
        }
        $name = Str::lower($data['display_name']);
        if (in_array($name, $seen['name'], true) || Client::whereRaw('LOWER(display_name) = ?', [$name])->exists()) {
            return 'name';
        }

        return null;
    }

    /** @return array<int, array<string,string>>|null rows keyed by line number */
    private function readCsv(string $path): ?array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            return null;
        }
        $header = null;
        $rows = [];
        $line = 0;
        while (($cols = fgetcsv($fh, escape: '')) !== false) {
            $line++;
            $cols = array_map(fn ($c) => preg_replace('/^\xEF\xBB\xBF/', '', (string) $c), $cols);
            if ($header === null) {
                $upper = array_map(fn ($c) => Str::upper(trim($c)), $cols);
                if (in_array('CUSTOMER', $upper, true)) {
                    $header = $upper;
                }

                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                if ($name !== '' && ! array_key_exists($name, $row)) {   // first occurrence wins (lookup columns repeat names)
                    $row[$name] = $cols[$i] ?? '';
                }
            }
            $rows[$line] = $row;
        }
        fclose($fh);

        return $header ? $rows : null;
    }

    private function clean(?string $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }
}
