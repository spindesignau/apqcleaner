<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /**
     * Send one SMS.
     *
     * @return array{ok: bool, provider_id: ?string, cost: ?float, error: ?string, retryable?: bool, blocked?: bool, simulated?: bool}
     */
    public function send(string $to, string $body, string $customRef): array;
}
