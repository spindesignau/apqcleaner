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
        'xero.default_account_code' => '200',      // Sales
        'xero.cancellation_account_code' => '260', // Other Revenue
        'xero.payment_terms_days' => 7,
        'xero.default_mode' => 'draft',            // draft | authorised
        'xero.email_by_default' => false,
        'xero.branding_theme_id' => null,
    ],

    // First administrator, created by `php artisan db:seed` (read via config so it works with config:cache).
    'admin' => [
        'email' => env('APQ_ADMIN_EMAIL'),
        'name' => env('APQ_ADMIN_NAME', 'Adriana Palacio'),
        'password' => env('APQ_ADMIN_PASSWORD'),
        'locale' => env('APQ_ADMIN_LOCALE', 'en'),
    ],

    'avatars' => [
        'disk' => 'local',
        'directory' => 'avatars',
        'size' => 512,
        'max_kb' => 2048,
    ],
];
