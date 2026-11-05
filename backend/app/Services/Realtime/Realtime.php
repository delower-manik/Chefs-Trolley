<?php

namespace App\Services\Realtime;

/** Resolves the configured publisher. Use Realtime::publisher()->publish(...). */
class Realtime
{
    public static function publisher(): Publisher
    {
        return app(Publisher::class);
    }

    public static function make(): Publisher
    {
        $timeout = (int) config('realtime.timeout_seconds', 2);

        return match (config('realtime.driver')) {
            'pusher', 'reverb' => new PusherPublisher(config('realtime.pusher'), $timeout),
            'ably' => new AblyPublisher(config('realtime.ably'), $timeout),
            'log' => new LogPublisher,
            default => new NullPublisher,
        };
    }
}
