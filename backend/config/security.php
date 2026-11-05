<?php

return [
    // Lock an account after this many wrong passwords / MFA codes in a row, for this many minutes.
    'lockout' => ['attempts' => (int) env('LOCKOUT_ATTEMPTS', 5), 'minutes' => (int) env('LOCKOUT_MINUTES', 15)],

    // Reject passwords found in known breaches (HaveIBeenPwned range API; only a 5-char hash prefix is sent).
    // Fails OPEN if the service can't be reached, so an outage never blocks signing in or changing a password.
    'breach_check' => (bool) env('SECURITY_BREACH_CHECK', true),

    'mfa' => ['issuer' => env('MFA_ISSUER', 'Chefs Trolley'), 'challenge_minutes' => 5, 'recovery_codes' => 10],

    // Sent with every API response.
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Cross-Origin-Resource-Policy' => 'same-site',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    ],
    'hsts' => (bool) env('SECURITY_HSTS', true), // only sent over HTTPS
];
