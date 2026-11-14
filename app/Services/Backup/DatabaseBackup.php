<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Nightly database backups to storage/app/private/backups (never web-accessible, never touched by deploys).
 *   MariaDB/MySQL → mysqldump if the host has it, otherwise a built-in PHP exporter
 *   SQLite        → a copy of the database file
 * Files are gzip-compressed and kept for `apq.backups.keep_days` days.
 */
class DatabaseBackup
{
    public const DIR = 'backups';

    /** @return array{path: string, bytes: int, method: string} */
    public function run(): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $stamp = Carbon::now(config('apq.display_timezone'))->format('Y-m-d_His');
        $driver = DB::connection()->getDriverName();
        $ext = $driver === 'sqlite' ? 'sqlite.gz' : 'sql.gz';
        $relative = self::DIR."/apq-db-{$stamp}.{$ext}";
        for ($n = 2; $disk->exists($relative); $n++) {   // two backups in the same second never overwrite each other
            $relative = self::DIR."/apq-db-{$stamp}-{$n}.{$ext}";
        }
        $target = $disk->path($relative);

        $method = match ($driver) {
            'sqlite' => $this->sqlite($target),
            'mysql', 'mariadb' => $this->mysql($target),
            default => throw new RuntimeException("Backups are not supported for the {$driver} driver."),
        };

        return ['path' => $relative, 'bytes' => filesize($target), 'method' => $method];
    }

    /** Delete backups older than the retention period. @return int files removed */
    public function prune(): int
    {
        $cutoff = now()->subDays((int) config('apq.backups.keep_days', 30))->getTimestamp();
        $disk = Storage::disk('local');
        $removed = 0;
        foreach ($disk->files(self::DIR) as $file) {
            if (str_starts_with(basename($file), 'apq-db-') && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /** @return array<int, array{name: string, bytes: int, at: Carbon}> newest first */
    public function list(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIR))
            ->filter(fn ($f) => str_starts_with(basename($f), 'apq-db-'))
            ->map(fn ($f) => ['name' => basename($f), 'bytes' => $disk->size($f), 'at' => Carbon::createFromTimestamp($disk->lastModified($f))])
            ->sortByDesc('at')->values()->all();
    }

    private function sqlite(string $target): string
    {
        $source = DB::connection()->getDatabaseName();
        if ($source === ':memory:' || ! is_file($source)) {
            // In-memory databases (tests) are exported as SQL instead.
            $this->writeGz($target, fn ($w) => $this->phpDumpSqlite($w));

            return 'php-export';
        }
        $this->writeGz($target, function ($w) use ($source) {
            $in = fopen($source, 'rb');
            while (! feof($in)) {
                $w(fread($in, 1 << 20));
            }
            fclose($in);
        });

        return 'file-copy';
    }

    private function mysql(string $target): string
    {
        $cfg = DB::connection()->getConfig();
        $binary = config('apq.backups.method', 'auto') === 'php'
            ? null
            : ((new ExecutableFinder)->find('mysqldump') ?? (new ExecutableFinder)->find('mariadb-dump'));

        if ($binary) {
            // Password via a temporary options file so it never appears in the process list.
            $options = tempnam(sys_get_temp_dir(), 'apqdump');
            file_put_contents($options, "[client]\nuser=\"{$cfg['username']}\"\npassword=\"".addcslashes((string) $cfg['password'], '"\\')."\"\nhost=\"{$cfg['host']}\"\nport={$cfg['port']}\n");
            chmod($options, 0600);
            try {
                $process = new Process([$binary, "--defaults-extra-file={$options}", '--single-transaction', '--quick', '--skip-lock-tables',
                    '--no-tablespaces', '--default-character-set=utf8mb4', $cfg['database']]);
                $process->setTimeout(600);
                $gz = gzopen($target, 'wb6');
                $process->run(function ($type, $buffer) use ($gz) {
                    if ($type === Process::OUT) {
                        gzwrite($gz, $buffer);
                    }
                });
                gzclose($gz);
                if ($process->isSuccessful()) {
                    return 'mysqldump';
                }
            } finally {
                @unlink($options);
            }
        }

        // No mysqldump (common on shared hosting) or it failed: built-in exporter.
        $this->writeGz($target, fn ($w) => $this->phpDumpMysql($w));

        return 'php-export';
    }

    private function writeGz(string $target, callable $producer): void
    {
        $gz = gzopen($target, 'wb6');
        if (! $gz) {
            throw new RuntimeException("Cannot write {$target}");
        }
        try {
            $producer(fn (string $chunk) => gzwrite($gz, $chunk));
        } finally {
            gzclose($gz);
        }
    }

    /** Plain SQL export: structure + data, restorable with `mysql dbname < file.sql`. */
    private function phpDumpMysql(callable $w): void
    {
        $pdo = DB::connection()->getPdo();
        $w("-- APQ Cleaning Service database backup\n-- ".now()->toIso8601String()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(\PDO::FETCH_NUM) as [$table]) {
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM)[1];
            $w("DROP TABLE IF EXISTS `{$table}`;\n{$create};\n\n");
            $this->dumpRows($pdo, $table, '`', $w);
        }
        $w("SET FOREIGN_KEY_CHECKS=1;\n");
    }

    private function phpDumpSqlite(callable $w): void
    {
        $pdo = DB::connection()->getPdo();
        $w("-- APQ Cleaning Service database backup (SQLite)\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
        foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_NUM) as [$table, $sql]) {
            $w("DROP TABLE IF EXISTS \"{$table}\";\n{$sql};\n");
            $this->dumpRows($pdo, $table, '"', $w);
        }
        $w("COMMIT;\n");
    }

    private function dumpRows(\PDO $pdo, string $table, string $q, callable $w): void
    {
        $stmt = $pdo->query("SELECT * FROM {$q}{$table}{$q}");
        $batch = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)).')';
            if (count($batch) === 200) {
                $w("INSERT INTO {$q}{$table}{$q} VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }
        if ($batch) {
            $w("INSERT INTO {$q}{$table}{$q} VALUES\n".implode(",\n", $batch).";\n");
        }
        $w("\n");
    }
}
