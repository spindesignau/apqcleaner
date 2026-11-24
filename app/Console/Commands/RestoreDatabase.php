<?php

namespace App\Console\Commands;

use App\Models\XeroConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loads an apq:backup file (.sql.gz or .sql) into this site's database — used to move staging's data to the live site.
 * Replaces every table in the dump. Afterwards clears what must not carry over between sites:
 * the Xero connection (its tokens are encrypted with the other site's APP_KEY), sessions, cache and queued jobs.
 */
class RestoreDatabase extends Command
{
    protected $signature = 'apq:restore {file : Path to a .sql.gz / .sql backup} {--force : Required — this replaces the current data}';

    protected $description = 'Replace this database with a backup made by apq:backup (e.g. copied from staging)';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Backup file not found: {$file}");

            return self::FAILURE;
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->error('apq:restore is for MariaDB/MySQL sites.');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->error('This replaces ALL data in '.DB::connection()->getDatabaseName().'. Run again with --force to continue.');

            return self::FAILURE;
        }

        $statements = $this->import($file);
        $this->info("Imported {$statements} SQL statements from ".basename($file).'.');

        $this->call('migrate', ['--force' => true]);
        $this->clearSiteSpecificData();
        $this->info('Cleared sessions, cache, queued jobs and the Xero connection. Connect Xero again on this site.');

        return self::SUCCESS;
    }

    /** Streams the dump statement by statement (both mysqldump and the built-in exporter end each statement with ";" at line end). */
    private function import(string $file): int
    {
        $handle = str_ends_with($file, '.gz') ? gzopen($file, 'rb') : fopen($file, 'rb');
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $buffer = '';
        $count = 0;

        try {
            while (($line = str_ends_with($file, '.gz') ? gzgets($handle) : fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                    continue;
                }
                $buffer .= $line;
                if (str_ends_with($trimmed, ';')) {
                    $pdo->exec($this->withDynamicRows($buffer));
                    $buffer = '';
                    $count++;
                }
            }
            if (trim($buffer) !== '') {
                $pdo->exec($buffer);
                $count++;
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            str_ends_with($file, '.gz') ? gzclose($handle) : fclose($handle);
        }

        return $count;
    }

    /**
     * Tables from another server may not name a row format; on hosts whose default is COMPACT they then fail
     * ("Row size too large" / 767-byte keys). Create them as DYNAMIC, like this app's migrations do.
     */
    private function withDynamicRows(string $sql): string
    {
        if (! preg_match('/^\s*CREATE TABLE/i', $sql) || stripos($sql, 'ROW_FORMAT') !== false) {
            return $sql;
        }

        return preg_replace('/;\s*$/', ' ROW_FORMAT=DYNAMIC;', rtrim($sql));
    }

    private function clearSiteSpecificData(): void
    {
        XeroConnection::query()->delete();
        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
