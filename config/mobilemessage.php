<?php

/*
 * Mobile Message (https://mobilemessage.com.au) — Australian SMS gateway.
 * ⚠ Endpoint and field names follow the provider's v1 API as documented in docs/04-SMS-MOBILE-MESSAGE.md
 *   and could not be checked against their live docs from the build environment. They are all in
 *   App\Services\Sms\MobileMessageGateway, so any difference is a one-file change.
 */
return [
    // false = record messages in the SMS log without sending them (local, staging, or until the account is set up).
    'enabled' => (bool) env('SMS_ENABLED', false),
    'base_url' => env('MOBILEMESSAGE_BASE_URL', 'https://api.mobilemessage.com.au/v1'),
    'username' => env('MOBILEMESSAGE_USERNAME'),
    'password' => env('MOBILEMESSAGE_PASSWORD'),
    'sender' => env('MOBILEMESSAGE_SENDER', 'APQClean'),
    'webhook_token' => env('MOBILEMESSAGE_WEBHOOK_TOKEN'),
    // Outside production, every SMS can be redirected to one test phone.
    'test_recipient' => env('SMS_TEST_RECIPIENT'),
    'timeout' => 15,
];
