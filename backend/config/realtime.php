<?php

/*
| Real-time push. Publishing is a short HTTP call made while handling the request (never queued):
| on shared hosting the queue only runs from cron (~1 min), far too slow for a kitchen screen.
| A failed publish is logged and ignored — clients still refresh on a slow poll.
|
| REALTIME_DRIVER: null | log | pusher | reverb | ably
| "reverb" speaks the Pusher protocol, so it uses the same `pusher` settings with your own host
| (Reverb itself needs a long-running process, which shared hosting does not allow).
*/
return [
    'driver' => env('REALTIME_DRIVER', 'null'),

    'pusher' => [
        'app_id' => env('PUSHER_APP_ID'),
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'cluster' => env('PUSHER_APP_CLUSTER', 'ap2'),
        'host' => env('PUSHER_HOST'),
        'port' => env('PUSHER_PORT'),
        'scheme' => env('PUSHER_SCHEME', 'https'),
    ],

    'ably' => [
        'key' => env('ABLY_KEY'), // "keyName:keySecret"
        'rest_url' => env('ABLY_REST_URL', 'https://rest.ably.io'),
    ],

    'timeout_seconds' => 2,
];
