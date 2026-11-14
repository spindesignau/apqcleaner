<?php

namespace App\Services\Sms;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\Audit;
use App\Services\Settings;
use App\Support\AuPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every outbound SMS goes through here:
 *  - client opt-out and missing/invalid mobile are respected (recorded as "skipped")
 *  - custom_ref is unique, so the same automatic reminder can never be sent twice
 *  - outside production every SMS can be redirected to SMS_TEST_RECIPIENT
 *  - the row is saved first, then sent, then updated with the result
 */
class SmsDispatcher
{
    public function __construct(private SmsGateway $gateway, private Settings $settings) {}

    public function remindClient(Appointment $appointment, string $trigger = 'manual', ?User $by = null): SmsMessage
    {
        $client = $appointment->client;
        $ref = $trigger === 'auto' ? "apt-{$appointment->id}-rem" : "apt-{$appointment->id}-man-".Str::lower(Str::random(6));
        $message = $this->toClient($client, 'reminder', $appointment, $trigger, $ref, $by);

        if ($trigger === 'auto' && in_array($message->status, ['sent', 'delivered'], true)) {
            $appointment->forceFill(['reminder_sent_at' => now()])->saveQuietly();
        }

        return $message;
    }

    public function cancellationNotice(Appointment $appointment, ?User $by = null): SmsMessage
    {
        return $this->toClient($appointment->client, 'cancellation', $appointment, 'system', "apt-{$appointment->id}-cxl", $by);
    }

    /** Tell assigned cleaners a visit was cancelled. */
    public function notifyStaffCancelled(Appointment $appointment): void
    {
        foreach ($appointment->staff as $person) {
            if (! $person->mobile) {
                continue;
            }
            $body = $this->render('staff_cancelled', $person->locale ?: 'en', $appointment);
            $this->send($person->mobile, $body, "apt-{$appointment->id}-stf-{$person->id}", [
                'user_id' => $person->id, 'appointment_id' => $appointment->id, 'template_key' => 'staff_cancelled', 'trigger' => 'system',
            ]);
        }
    }

    public function freeText(Client $client, string $body, User $by): SmsMessage
    {
        return $this->guarded($client, $body, 'msg-'.Str::lower(Str::random(12)), [
            'client_id' => $client->id, 'template_key' => null, 'trigger' => 'manual', 'sent_by' => $by->id,
        ]);
    }

    /** Fill a template's placeholders for an appointment, in the recipient's language. */
    public function render(string $key, string $locale, ?Appointment $appointment = null, ?Client $client = null): string
    {
        $template = SmsTemplate::where('key', $key)->first();
        $body = $template?->body($locale) ?? '';
        $client ??= $appointment?->client;
        $start = $appointment?->localStart()->locale($locale);

        return trim(strtr($body, [
            '{first_name}' => $client ? Str::title(Str::lower(Str::before(Str::before($client->display_name, ' – '), ' '))) : '',
            '{client}' => $client?->display_name ?? '',
            // English capitalises weekdays ("Tuesday"); Spanish doesn't mid-sentence ("el martes").
            '{day}' => $start ? ($locale === 'es' ? $start->translatedFormat('l') : ucfirst($start->translatedFormat('l'))) : '',
            '{date}' => $start ? $start->translatedFormat('j M') : '',
            '{time}' => $start ? $start->format($locale === 'es' ? 'H:i' : 'g:ia') : '',
            '{business_phone}' => (string) $this->settings->get('business.phone', ''),
        ]));
    }

    /** Automatic messages only go out between quiet_end and quiet_start (local time). */
    public function withinSendingHours(?Carbon $at = null): bool
    {
        $now = ($at ?? Carbon::now())->copy()->tz(config('apq.display_timezone'))->format('H:i');

        return $now >= (string) $this->settings->get('sms.quiet_end', '08:00') && $now < (string) $this->settings->get('sms.quiet_start', '20:00');
    }

    /** An Australian mobile (+614…) or an overseas number; Australian landlines can't receive SMS. */
    public static function textable(?string $number): bool
    {
        return AuPhone::isMobile($number) || (str_starts_with((string) $number, '+') && ! str_starts_with((string) $number, '+61'));
    }

    public static function segments(string $body): int
    {
        $gsm = ! preg_match('/[^\x0A\x0D\x20-\x7E£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ¡ÄÖÑÜ§¿äöñüà€\[\]{}\\\\^~|]/u', $body);
        $len = mb_strlen($body);

        return $gsm ? ($len <= 160 ? 1 : (int) ceil($len / 153)) : ($len <= 70 ? 1 : (int) ceil($len / 67));
    }

    private function toClient(Client $client, string $key, Appointment $appointment, string $trigger, string $ref, ?User $by): SmsMessage
    {
        return $this->guarded($client, $this->render($key, $client->preferred_language ?: 'en', $appointment), $ref, [
            'client_id' => $client->id, 'appointment_id' => $appointment->id, 'template_key' => $key, 'trigger' => $trigger, 'sent_by' => $by?->id,
        ]);
    }

    /** Client messages: skip opted-out clients and missing/invalid mobiles, recording why. */
    private function guarded(Client $client, string $body, string $ref, array $meta): SmsMessage
    {
        $skip = match (true) {
            ! $client->sms_opt_in || $client->sms_opted_out_at !== null => __('sms.skip_opted_out'),
            ! self::textable($client->mobile) => __('sms.skip_no_mobile'),
            default => null,
        };
        if ($skip) {
            return $this->record($client->mobile, $body, $ref, $meta + ['status' => 'skipped', 'status_detail' => $skip]);
        }

        return $this->send($client->mobile, $body, $ref, $meta);
    }

    private function send(string $to, string $body, string $ref, array $meta): SmsMessage
    {
        $redirect = ! app()->isProduction() ? config('mobilemessage.test_recipient') : null;
        $message = $this->record($redirect ?: $to, $body, $ref, $meta + ['status' => 'queued',
            'status_detail' => $redirect ? __('sms.redirected', ['to' => $to]) : null]);
        if ($message->wasRecentlyCreated === false) {
            return $message;   // same custom_ref already handled — never send twice
        }

        $result = $this->gateway->send($message->to_number, $body, $ref);
        $message->update([
            'status' => $result['ok'] ? 'sent' : 'failed',
            'provider_message_id' => $result['provider_id'],
            'cost_credits' => $result['cost'],
            'status_detail' => $result['ok']
                ? (($result['simulated'] ?? false) ? __('sms.simulated') : $message->status_detail)
                : mb_substr((string) $result['error'], 0, 255),
            'sent_at' => $result['ok'] ? now() : null,
        ]);
        Audit::log($result['ok'] ? 'sms.sent' : 'sms.failed', $message);

        return $message;
    }

    private function record(?string $to, string $body, string $ref, array $attrs): SmsMessage
    {
        try {
            return SmsMessage::create($attrs + [
                'direction' => 'outbound', 'to_number' => $to, 'from_number' => config('mobilemessage.sender'),
                'body' => $body, 'segments' => self::segments($body), 'custom_ref' => $ref,
            ]);
        } catch (UniqueConstraintViolationException) {
            return SmsMessage::where('custom_ref', $ref)->firstOrFail();
        }
    }
}
