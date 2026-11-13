<?php

namespace App\Services\Import;

use App\Enums\PaymentMethod;
use App\Enums\PricingModel;
use App\Models\Client;
use App\Models\ServiceType;
use App\Services\Audit;
use App\Support\AuPhone;
use App\Support\Suburbs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the "Datos" client sheet (CSV export) into a reviewable plan, then commits it.
 * Used by both `php artisan apq:import:clients` and Admin → Clients → Import.
 *
 * Duplicates: a row is skipped only when its client name matches another row or an existing client.
 * Rows sharing a phone/email are imported with a warning (families and multi-site businesses share numbers).
 */
class ClientImporter
{
    public const CANCELLED_SKIP = 'skip';

    public const CANCELLED_INACTIVE = 'inactive';

    public const CANCELLED_ACTIVE = 'active';

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

    /**
     * @return array{rows: array<int, array{line:int, data:array, action:string, note:?string, warnings:string[]}>, counts: array<string,int>}|null
     */
    public function plan(string $path, string $cancelled = self::CANCELLED_SKIP): ?array
    {
        $raw = $this->readCsv($path);
        if ($raw === null) {
            return null;
        }

        $serviceTypes = ServiceType::pluck('id', 'name_en');
        $existingNames = Client::withTrashed()->pluck('display_name')->map(fn ($n) => $this->key($n))->flip();
        $seenNames = [];
        $seenContacts = [];
        $rows = [];
        $counts = ['create' => 0, 'cancelled' => 0, 'duplicate' => 0, 'empty' => 0, 'warnings' => 0];

        foreach ($raw as $line => $r) {
            $name = $this->clean($r['CUSTOMER'] ?? '');
            if ($name === '') {
                $counts['empty']++;

                continue;
            }

            $isCancelled = Str::lower($this->clean($r['STATE'] ?? '')) === 'cancelled';
            if ($isCancelled && $cancelled === self::CANCELLED_SKIP) {
                $counts['cancelled']++;

                continue;
            }

            $warnings = [];
            $data = $this->map($r, $name, $serviceTypes, $warnings);
            $data['is_active'] = ! ($isCancelled && $cancelled === self::CANCELLED_INACTIVE);
            $key = $this->key($data['display_name']);

            if (isset($seenNames[$key]) || $existingNames->has($key)) {
                $counts['duplicate']++;
                $rows[] = ['line' => $line, 'data' => $data, 'action' => 'skip',
                    'note' => isset($seenNames[$key]) ? __('import.dup_in_file', ['line' => $seenNames[$key]]) : __('import.dup_existing'),
                    'warnings' => []];

                continue;
            }
            $seenNames[$key] = $line;

            foreach (['mobile', 'email'] as $field) {
                $value = $data[$field] ? Str::lower($data[$field]) : null;
                if ($value && isset($seenContacts[$field][$value])) {
                    $warnings[] = __('import.shares_'.$field, ['name' => $seenContacts[$field][$value]]);
                } elseif ($value && ($other = Client::where($field, $data[$field])->value('display_name'))) {
                    $warnings[] = __('import.shares_'.$field, ['name' => $other]);
                }
                if ($value) {
                    $seenContacts[$field][$value] ??= $data['display_name'];
                }
            }

            $counts['create']++;
            $counts['warnings'] += $warnings ? 1 : 0;
            $rows[] = ['line' => $line, 'data' => $data, 'action' => 'create', 'note' => null, 'warnings' => $warnings];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** @return int number of clients created */
    public function commit(array $plan, string $source = 'client spreadsheet'): int
    {
        return DB::transaction(function () use ($plan, $source) {
            $created = 0;
            foreach ($plan['rows'] as $row) {
                if ($row['action'] !== 'create') {
                    continue;
                }
                $client = Client::create($row['data']);
                Audit::log('client.imported', $client, reason: "Imported from {$source}");
                $created++;
            }

            return $created;
        });
    }

    private function map(array $r, string $name, $serviceTypes, array &$warnings): array
    {
        $serviceAddress = $this->blankIfZero($r['SERVICE ADDRESS'] ?? '') ?: $this->blankIfZero($r['ADDRESS'] ?? '');
        $billingAddress = $this->blankIfZero($r['ADDRESS'] ?? '');
        [$street, $suburb, $postcode] = Suburbs::split($serviceAddress);
        if ($street === '') {
            $warnings[] = __('import.no_address');
        } elseif ($suburb === null) {
            $warnings[] = __('import.no_suburb');
        }

        $phones = array_values(array_filter(array_map('trim', preg_split('/\s[-\/]\s|\/|,|;/', (string) ($r['PHONE'] ?? '')))));
        $mobile = $this->phone($phones[0] ?? null);
        $alt = $this->phone($phones[1] ?? null);
        if ($mobile && ! str_starts_with($mobile, '+')) {
            $warnings[] = __('import.bad_phone', ['value' => $mobile]);
            $mobile = null;
        }

        $adminNotes = [];
        $rawEmail = Str::lower($this->clean($r['EMAIL'] ?? ''));
        $emails = array_values(array_filter(preg_split('/[\s,;\/]+/', $rawEmail), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $email = $emails[0] ?? null;
        if (count($emails) > 1) {
            $adminNotes[] = 'Other emails: '.implode(', ', array_slice($emails, 1));
        }
        if ($rawEmail !== '' && $email === null) {
            $adminNotes[] = "Email in spreadsheet looked invalid: {$rawEmail}";
            $warnings[] = __('import.bad_email', ['value' => $rawEmail]);
        }
        if ($gift = $this->clean($r['GIFTS'] ?? '')) {
            $adminNotes[] = "Gifts: {$gift}";
        }

        $freqRaw = Str::lower($this->clean($r['HOW OFTEN'] ?? ''));
        $typeRaw = Str::lower($this->clean($r['TYPE CLEANING SERVICE'] ?? ''));
        $display = Str::limit(Str::title(Str::lower($name)), 150, '');

        return [
            'display_name' => $display,
            'billing_name' => $display,
            'email' => $email,
            'mobile' => $mobile,
            'phone_alt' => $alt && str_starts_with($alt, '+') ? $alt : null,
            'address_line1' => $street !== '' ? Str::limit($street, 190, '') : '(address missing)',
            'suburb' => $suburb,
            'postcode' => $postcode,
            'state' => 'NSW',
            'billing_address' => $billingAddress && $billingAddress !== $serviceAddress ? Str::limit($billingAddress, 255, '') : null,
            'access_notes' => $this->clean($r['METHOD OF ENTRY'] ?? '') ?: null,
            'default_job_notes' => $this->clean($r['FOCUS ON:'] ?? '') ?: null,
            'admin_notes' => $adminNotes ? implode("\n", $adminNotes) : null,
            'service_type_id' => $serviceTypes[self::SERVICE_TYPES[$typeRaw] ?? 'General Service'] ?? null,
            'pricing_model' => PricingModel::Fixed,
            'payment_method' => Str::contains(Str::lower($r['TYPE OF PAYMENT'] ?? ''), 'abn') ? PaymentMethod::Abn : PaymentMethod::Cash,
            'usual_frequency' => self::FREQUENCIES[$freqRaw] ?? ($freqRaw !== '' ? Str::limit(Str::title($freqRaw), 40, '') : null),
            'referral_source' => Str::limit($this->clean($r['HOW YOU CONTACT US'] ?? ''), 150, '') ?: null,
            'preferred_language' => 'en',
            'sms_opt_in' => true,
        ];
    }

    private function phone(?string $raw): ?string
    {
        if ($raw === null || strlen(preg_replace('/\D/', '', $raw)) < 8) {
            return null;   // "0", blanks and fragments
        }

        return AuPhone::normalise($raw);
    }

    private function blankIfZero(?string $v): string
    {
        $v = $this->clean($v);

        return $v === '0' ? '' : $v;
    }

    private function key(string $name): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim($name)));
    }

    /** @return array<int, array<string,string>>|null rows keyed by line number */
    private function readCsv(string $path): ?array
    {
        $fh = @fopen($path, 'r');
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
