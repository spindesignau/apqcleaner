<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\XeroConnection;
use App\Support\Changelog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Health checks for the admin System page and dashboard banner.
 * The scheduler (cPanel cron) drives everything automatic: new visits, reminders, Xero pushes, backups.
 */
class SystemStatus
{
    public function __construct(private Settings $settings) {}

    /** @return array<int, array{key: string, ok: bool|null, detail: string}> ok=null means "not set up yet" (informational) */
    public function checks(): array
    {
        $heartbeat = $this->time('system.scheduler_heartbeat');
        $oldestJob = DB::table('jobs')->min('available_at');
        $failedJobs = DB::table('failed_jobs')->count();
        $lastBackup = $this->time('system.last_backup_at');

        return [
            ['key' => 'scheduler', 'ok' => $heartbeat && $heartbeat->gt(now()->subMinutes(10)),
                'detail' => $heartbeat ? __('system.last_seen', ['when' => $heartbeat->diffForHumans()]) : __('system.never_run')],
            ['key' => 'queue', 'ok' => ! $oldestJob || Carbon::createFromTimestamp($oldestJob)->gt(now()->subMinutes(10)),
                'detail' => $oldestJob ? __('system.oldest_job', ['when' => Carbon::createFromTimestamp($oldestJob)->diffForHumans()]) : __('system.queue_empty')],
            ['key' => 'failed_jobs', 'ok' => $failedJobs === 0, 'detail' => trans_choice('system.failed_jobs_count', $failedJobs, ['count' => $failedJobs])],
            ['key' => 'backup', 'ok' => $lastBackup ? $lastBackup->gt(now()->subHours(26)) : null,
                'detail' => $lastBackup ? __('system.last_backup', ['when' => $lastBackup->diffForHumans()]) : __('system.no_backup')],
            ['key' => 'xero', 'ok' => XeroConnection::current() ? Invoice::where('sync_status', Invoice::SYNC_FAILED)->doesntExist() : null,
                'detail' => XeroConnection::current() ? XeroConnection::current()->tenant_name : __('xero.not_connected')],
            ['key' => 'sms', 'ok' => config('mobilemessage.enabled') ? true : null,
                'detail' => config('mobilemessage.enabled') ? __('system.sms_live') : __('system.sms_test')],
            ['key' => 'debug', 'ok' => ! (app()->environment('production') && config('app.debug')),
                'detail' => config('app.debug') ? __('system.debug_on') : __('system.debug_off')],
            ['key' => 'environment', 'ok' => null,
                'detail' => app()->environment().' · PHP '.PHP_VERSION.' · '.DB::connection()->getDriverName().' · v'.(app(Changelog::class)->version() ?? '?')],
        ];
    }

    /** Problems serious enough for a dashboard banner. */
    public function problems(): array
    {
        return collect($this->checks())->whereIn('key', ['scheduler', 'queue'])->where('ok', false)->pluck('key')->all();
    }

    private function time(string $key): ?Carbon
    {
        $v = $this->settings->get($key);

        return $v ? Carbon::parse($v) : null;
    }
}
