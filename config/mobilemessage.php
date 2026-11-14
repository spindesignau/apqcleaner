<?php

/*
 * Mobile Message (https://mobilemessage.com.au) — Australian SMS gateway, v1 API.
 * Checked against https://mobilemessage.com.au/api-documentation on 14 Nov 2026 (App\Services\Sms\MobileMessageGateway).
 * Testing without texting real phones: create a sandbox under Settings > API in their web app and use its keys;
 * numbers ending in 000 simulate a failed delivery.
 */
return [
    // false = record messages in the SMS log without sending them (local, staging, or until the account is set up).
    'enabled' => (bool) env('SMS_ENABLED', false),
    'base_url' => env('MOBILEMESSAGE_BASE_URL', 'https://api.mobilemessage.com.au/'),
    'username' => env('MOBILEMESSAGE_USERNAME'),
    'password' => env('MOBILEMESSAGE_PASSWORD'),
    // Must be a sender ID approved on the account (Admin → SMS → Check account lists them).
    'sender' => env('MOBILEMESSAGE_SENDER', 'APQClean'),
    'webhook_token' => env('MOBILEMESSAGE_WEBHOOK_TOKEN'),
    // Optional: Settings > API > Webhook Signing Secret. When set, webhooks must carry a valid X-MM-Signature.
    'webhook_secret' => env('MOBILEMESSAGE_WEBHOOK_SECRET'),
    // Outside production, every SMS can be redirected to one test phone.
    'test_recipient' => env('SMS_TEST_RECIPIENT'),
    'timeout' => 15,
];
