<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Used while SMS_ENABLED=false: messages are recorded in the SMS log but never leave the server. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $body, string $customRef): array
    {
        Log::info('SMS (not sent, SMS_ENABLED=false)', ['to' => $to, 'ref' => $customRef, 'length' => mb_strlen($body)]);

        return ['ok' => true, 'provider_id' => null, 'cost' => 0.0, 'error' => null, 'simulated' => true];
    }
}
