<?php

/*
| Outgoing notifications (SMS / email / push). Credentials come from .env; nothing is sent until a
| driver is configured. Deliveries are queued (database queue + cron on shared hosting) and logged in
| `notification_deliveries`.
*/
return [
    // Safety limit: a campaign refuses to send to more people than this in one go (SMS costs money per message).
    'campaign_max_recipients' => (int) env('CAMPAIGN_MAX_RECIPIENTS', 5000),

    // Transactional SMS/push about a customer's own online order (delivery, takeaway, app/web).
    'order_sms' => (bool) env('NOTIFY_ORDER_SMS', false),
    'order_push' => (bool) env('NOTIFY_ORDER_PUSH', false),

    'sms' => [
        // none | twilio | bulksmsbd | sslwireless | greenweb | alphasms | elitbuzz
        'driver' => env('SMS_DRIVER', 'none'),
        'timeout' => 10,

        'twilio' => [
            'sid' => env('TWILIO_SID'), 'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'), 'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        ],
        'bulksmsbd' => ['url' => env('BULKSMSBD_URL', 'https://bulksmsbd.net/api/smsapi'), 'api_key' => env('BULKSMSBD_API_KEY'), 'sender_id' => env('BULKSMSBD_SENDER_ID')],
        'sslwireless' => ['url' => env('SSLWIRELESS_URL', 'https://smsplus.sslwireless.com/api/v3/send-sms'), 'api_token' => env('SSLWIRELESS_API_TOKEN'), 'sid' => env('SSLWIRELESS_SID')],
        'greenweb' => ['url' => env('GREENWEB_URL', 'https://api.greenweb.com.bd/api.php'), 'token' => env('GREENWEB_TOKEN')],
        'alphasms' => ['url' => env('ALPHASMS_URL', 'https://api.sms.net.bd/sendsms'), 'api_key' => env('ALPHASMS_API_KEY')],
        'elitbuzz' => ['url' => env('ELITBUZZ_URL', 'https://msg.elitbuzz-bd.com/smsapi'), 'api_key' => env('ELITBUZZ_API_KEY'), 'sender_id' => env('ELITBUZZ_SENDER_ID')],
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        // Service-account JSON: raw JSON, base64 of it, or a file path.
        'credentials' => env('FCM_CREDENTIALS'),
        'timeout' => 10,
    ],

    'webhooks' => [
        'timeout' => 8,
        'backoff_minutes' => [1, 5, 30, 120, 360],
        'allow_insecure' => (bool) env('WEBHOOKS_ALLOW_INSECURE', false), // http:// and private hosts (local dev only)
    ],
];
