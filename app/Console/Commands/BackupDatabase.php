<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\Backup\DatabaseBackup;
use App\Services\Settings;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'apq:backup';

    protected $description = 'Back up the database to storage/app/private/backups and remove old backups';

    public function handle(DatabaseBackup $backup, Settings $settings): int
    {
        $result = $backup->run();
        $removed = $backup->prune();
        $settings->set('system.last_backup_at', now()->toIso8601String());
        Audit::log('system.backup', null, after: $result + ['pruned' => $removed]);

        $this->info(sprintf('Backup written: %s (%s KB, %s). Old backups removed: %d.', $result['path'], number_format($result['bytes'] / 1024, 1), $result['method'], $removed));

        return self::SUCCESS;
    }
}
