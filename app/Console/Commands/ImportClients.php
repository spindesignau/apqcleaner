<?php

namespace App\Console\Commands;

use App\Services\Import\ClientImporter;
use App\Support\AuPhone;
use Illuminate\Console\Command;

/**
 * Imports the "Datos" client sheet (CSV export). See docs/01-SPECIFICATION.md §7.
 * The same import is available in the browser: Admin → Clients → Import from spreadsheet.
 */
class ImportClients extends Command
{
    protected $signature = 'apq:import:clients
        {file : Path to the client CSV (Datos sheet)}
        {--cancelled=skip : Rows marked "Cancelled": skip, inactive or active}
        {--include-cancelled : Shortcut for --cancelled=inactive}
        {--commit : Write to the database (otherwise dry run)}';

    protected $description = 'Import clients from the client database CSV';

    public function handle(ClientImporter $importer): int
    {
        $mode = $this->option('include-cancelled') ? ClientImporter::CANCELLED_INACTIVE : $this->option('cancelled');
        if (! in_array($mode, [ClientImporter::CANCELLED_SKIP, ClientImporter::CANCELLED_INACTIVE, ClientImporter::CANCELLED_ACTIVE], true)) {
            $this->error('--cancelled must be skip, inactive or active.');

            return self::INVALID;
        }

        $plan = $importer->plan($this->argument('file'), $mode);
        if ($plan === null) {
            $this->error('Could not find the header row (expected a "CUSTOMER" column).');

            return self::FAILURE;
        }

        $this->table(['Line', 'Client', 'Suburb', 'Phone', 'Action', 'Notes'], array_map(fn ($r) => [
            $r['line'], $r['data']['display_name'], $r['data']['suburb'] ?? '—', AuPhone::display($r['data']['mobile']) ?? '—',
            $r['action'] === 'create' ? ($r['data']['is_active'] ? 'create' : 'create (inactive)') : 'skip',
            $r['note'] ?? implode('; ', $r['warnings']),
        ], $plan['rows']));

        $c = $plan['counts'];
        $this->line("To create: <info>{$c['create']}</info> · Duplicates skipped: {$c['duplicate']} · Cancelled skipped: {$c['cancelled']} · With warnings: {$c['warnings']}");

        if (! $this->option('commit')) {
            $this->line('<comment>Dry run — nothing saved. Re-run with --commit.</comment>');

            return self::SUCCESS;
        }

        $created = $importer->commit($plan);
        $this->info("Imported {$created} client(s). Set each client's price and regular schedule in Admin → Clients.");

        return self::SUCCESS;
    }
}
