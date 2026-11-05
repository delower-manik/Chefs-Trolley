<?php

/*
| Online payment gateways. Credentials are stored encrypted per gateway (Integrations → Payments);
| nothing here is a secret. Callbacks are reached at APP_URL/api/v1/payment-callbacks/…, so APP_URL
| must be the public URL of the API, and the customer is sent back to FRONTEND_URL afterwards.
*/
return [
    'session_minutes' => (int) env('GATEWAY_SESSION_MINUTES', 30),
    // Where customers land after paying for a website order (the public site, not the admin console).
    'website_url' => env('WEBSITE_URL', 'http://localhost:3001'),
    'return_url' => env('GATEWAY_RETURN_URL', env('FRONTEND_URL', 'http://localhost:3000')),
];
