<?php

return [
    'client_id' => env('XERO_CLIENT_ID'),
    'client_secret' => env('XERO_CLIENT_SECRET'),
    'redirect_uri' => env('XERO_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/admin/xero/callback'),
    'webhook_key' => env('XERO_WEBHOOK_KEY'),

    // Newer Xero apps use granular scopes (accounting.invoices). Older apps: replace accounting.invoices
    // with accounting.transactions. Must match the scopes ticked on the app at developer.xero.com.
    'scopes' => env('XERO_SCOPES', 'openid profile email offline_access accounting.invoices accounting.contacts accounting.settings.read'),

    'urls' => [
        'authorize' => 'https://login.xero.com/identity/connect/authorize',
        'token' => 'https://identity.xero.com/connect/token',
        'revoke' => 'https://identity.xero.com/connect/revocation',
        'connections' => 'https://api.xero.com/connections',
        'api' => 'https://api.xero.com/api.xro/2.0',
    ],

    'invoice_url' => 'https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=',
    'contact_url' => 'https://go.xero.com/Contacts/View/',
];
