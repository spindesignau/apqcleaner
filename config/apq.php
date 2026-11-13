<?php

return [
    'locales' => ['en' => 'English', 'es' => 'Español'],

    'display_timezone' => env('APQ_DISPLAY_TIMEZONE', 'Australia/Sydney'),

    // Defaults for the `settings` table (see docs/02-DATABASE-SCHEMA.md §2.16).
    'defaults' => [
        'business.name' => 'APQ Cleaning Service',
        'business.phone' => env('APQ_BUSINESS_PHONE'),
        'display_timezone' => env('APQ_DISPLAY_TIMEZONE', 'Australia/Sydney'),
        'pay_period' => 'fortnightly',
        'pay_period_anchor' => '2026-01-05',   // a Monday that starts a pay fortnight
        'labour_on_cost_percent' => 12.0,
        'timer.start_early_minutes' => 60,
        'timer.overrun_alert_hours' => 3,
        'notify_staff_on_cancel' => true,
    ],

    'avatars' => [
        'disk' => 'local',
        'directory' => 'avatars',
        'size' => 512,
        'max_kb' => 2048,
    ],
];
