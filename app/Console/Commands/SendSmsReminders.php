<?php

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Settings;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Day-before reminders: from sms.reminder_time (default 4pm, Sydney) every scheduled visit tomorrow gets one SMS.
 * Never outside sending hours; each visit is reminded at most once (unique custom_ref).
 */
class SendSmsReminders extends Command
{
    protected $signature = 'apq:sms:send-reminders';

    protected $description = 'Send day-before SMS reminders to clients';

    public function handle(SmsDispatcher $sms, Settings $settings): int
    {
        $tz = config('apq.display_timezone');
        $now = Carbon::now($tz);

        if (! $settings->get('sms.reminders_enabled', true)) {
            $this->line('Automatic reminders are turned off.');

            return self::SUCCESS;
        }
        if ($now->format('H:i') < (string) $settings->get('sms.reminder_time', '16:00') || ! $sms->withinSendingHours($now)) {
            $this->line('Not reminder time yet.');

            return self::SUCCESS;
        }

        $tomorrow = $now->copy()->addDay();
        $sent = 0;
        Appointment::query()
            ->with('client')
            ->where('status', AppointmentStatus::Scheduled->value)
            ->whereNull('reminder_sent_at')
            ->betweenLocalDates($tomorrow, $tomorrow)
            ->whereDoesntHave('smsMessages', fn ($q) => $q->where('template_key', 'reminder')->where('trigger', 'auto'))
            ->orderBy('scheduled_start')
            ->each(function (Appointment $appointment) use ($sms, &$sent) {
                $message = $sms->remindClient($appointment, 'auto');
                $sent += $message->status === 'sent' ? 1 : 0;
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
