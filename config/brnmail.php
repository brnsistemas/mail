<?php

return [
    'bootstrap_url' => env('BRNMAIL_BOOTSTRAP_URL'),
    'bootstrap_db_port' => env('BRNMAIL_BOOTSTRAP_DB_PORT', 33461),
    'transport' => env('BRNMAIL_TRANSPORT', 'local'),
    'external_enabled' => (bool) env('BRNMAIL_EXTERNAL_ENABLED', false),
    'local_demo' => (bool) env('BRNMAIL_LOCAL_DEMO', false),
    'resend_key' => env('RESEND_API_KEY'),
    'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    'test_recipients' => array_filter(array_map('trim', explode(',', env('RESEND_TEST_RECIPIENTS', '')))),
    'scanner' => env('BRNMAIL_SCANNER', 'clamd'),
    'clamd_host' => env('BRNMAIL_CLAMD_HOST', '127.0.0.1'),
    'clamd_port' => (int) env('BRNMAIL_CLAMD_PORT', 13310),
    'attachment_max' => 10 * 1024 * 1024,
    'attachments_total' => 20 * 1024 * 1024,
    'attachments_count' => 5,
    'daily_limit' => 100,
];
