<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\Backup\DatabaseBackup;
use App\Services\Settings;
use App\Services\SystemStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{
    public function index(SystemStatus $status, DatabaseBackup $backups): View
    {
        return view('admin.settings.index', ['checks' => $status->checks(), 'backups' => array_slice($backups->list(), 0, 10)]);
    }

    public function backupNow(DatabaseBackup $backup, Settings $settings): RedirectResponse
    {
        $result = $backup->run();
        $backup->prune();
        $settings->set('system.last_backup_at', now()->toIso8601String());
        Audit::log('system.backup', null, after: $result + ['manual' => true]);

        return back()->with('status', __('system.backup_done', ['size' => number_format($result['bytes'] / 1024, 1)]));
    }

    /** Admin-only download of a backup file (they contain client data — keep them safe). */
    public function download(string $name): StreamedResponse
    {
        abort_unless(preg_match('/^apq-db-[0-9_-]+\.(sql|sqlite)\.gz$/', $name), 404);
        $path = DatabaseBackup::DIR.'/'.$name;
        abort_unless(Storage::disk('local')->exists($path), 404);
        Audit::log('system.backup_downloaded', null, after: ['file' => $name]);

        return Storage::disk('local')->download($path);
    }
}
