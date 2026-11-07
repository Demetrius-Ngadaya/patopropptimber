<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Product brand
    |--------------------------------------------------------------------------
    | The software's own name, shown in emails, PDFs and receipts as
    | "Powered by SHEZONI PatoPro". The business using it keeps its own name
    | and logo (Settings → Business).
    */
    'brand' => [
        'product' => 'PatoPro',
        'company' => 'SHEZONI',
        'company_legal' => 'SHEZONI Technologies Ltd',
        'version' => '1.0',
        'powered_by' => 'Powered by SHEZONI PatoPro',
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Document number prefixes
    |--------------------------------------------------------------------------
    | Numbers are generated as PREFIX-YEAR-000001 and are unique per type/year.
    */
    'prefixes' => [
        'sale' => 'INV',
        'order' => 'ORD',
        'online_payment' => 'OP',
        'purchase' => 'PUR',
        'purchase_return' => 'PRN',
        'sale_return' => 'SRN',
        'transfer' => 'TRF',
        'allocation' => 'ALC',
        'customer' => 'CUS',
        'supplier' => 'SUP',
        'adjustment' => 'ADJ',
        'payment' => 'RCP',
        'supplier_payment' => 'SPY',
        'expense' => 'EXP',
        'savings' => 'SAV',
        'savings_tx' => 'STX',
        'journal' => 'JE',
        'fixed_asset' => 'FA',
        'employee' => 'EMP',
        'leave' => 'LV',
        'advance' => 'ADV',
        'payroll' => 'PR',
        'quotation' => 'QT',
        'proforma' => 'PF',
        'invoice' => 'IV',
        'delivery' => 'DL',
        'purchase_plan' => 'PO',
        'promotion' => 'PRM',
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS (Webline Africa bulk SMS API)
    |--------------------------------------------------------------------------
    */
    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
        'api_url' => env('SMS_API_URL', 'https://sms.webline.africa/api/v3/sms/send'),
        'token' => env('SMS_API_TOKEN'),
        'sender_id' => env('SMS_SENDER_ID', 'TAARIFA'),
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Meta WhatsApp Business Cloud API)
    |--------------------------------------------------------------------------
    | Create an app at developers.facebook.com, add the WhatsApp product and
    | copy the permanent access token and the phone number ID.
    |
    | WhatsApp only lets a business start a conversation with an approved
    | message template. Create a UTILITY template with ONE body variable,
    | e.g. "{{1}}", and put its name here: every notification is sent through
    | it. Without a template, plain text is sent, which WhatsApp only delivers
    | to people who messaged your number in the last 24 hours.
    */
    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'template' => env('WHATSAPP_TEMPLATE'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Online payments (Snippe, https://snippe.sh)
    |--------------------------------------------------------------------------
    | Customers pay from their M-Pesa, Airtel Money, Mixx by Yas or HaloPesa
    | wallet: they get a USSD push and enter their PIN. Create an API key with
    | the scopes collection:read and collection:create (Settings -> API Keys in
    | the Snippe dashboard) and copy the webhook signing key (Settings ->
    | Webhook Secret). The admin switches online payments on for the till and
    | the online shop in Settings -> Online payments.
    |
    | The webhook address must be reachable over HTTPS from the internet; it is
    | {APP_URL}/api/v1/webhooks/snippe unless SNIPPE_WEBHOOK_URL says otherwise.
    */
    'snippe' => [
        'api_key' => env('SNIPPE_API_KEY'),
        'webhook_secret' => env('SNIPPE_WEBHOOK_SECRET'),
        'base_url' => env('SNIPPE_BASE_URL', 'https://api.snippe.sh'),
        'webhook_url' => env('SNIPPE_WEBHOOK_URL'),
        'timeout' => 30,
        // Snippe rejects payments below this amount (TZS).
        'minimum' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    */
    'backup' => [
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
        'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    | Request fields that are never written to the activity log.
    */
    'audit' => [
        'redact' => ['password', 'password_confirmation', 'current_password', 'new_password', 'token', 'api_token'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile app push notifications (Expo push service)
    |--------------------------------------------------------------------------
    | EXPO_ACCESS_TOKEN is optional: set it when "enhanced push security" is
    | on for the project at expo.dev. MOBILE_PUSH_ENABLED=false stops sending.
    */
    'mobile' => [
        'push_enabled' => (bool) env('MOBILE_PUSH_ENABLED', true),
        'expo_push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
        'expo_access_token' => env('EXPO_ACCESS_TOKEN'),
        'pull_page_size' => (int) env('MOBILE_SYNC_PAGE_SIZE', 500),
    ],
];
