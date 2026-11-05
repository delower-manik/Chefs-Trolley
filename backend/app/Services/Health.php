<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Liveness/readiness checks for load balancers and uptime monitors.
 *
 * "Critical" checks (database, cache, storage) decide the HTTP status. "Soft" checks (queue backlog, scheduler heartbeat)
 * only report: on shared hosting the cron may legitimately lag, and restarting a container would not fix that.
 */
class Health
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /** @return array{ok: bool, checks: array<string, string>, details: array<string, mixed>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->probe(fn () => DB::select('select 1')),
            'cache' => $this->probe(function () {
                $k = 'health:probe:'.bin2hex(random_bytes(4));
                Cache::put($k, '1', 10);
                $ok = Cache::get($k) === '1';
                Cache::forget($k);
                if (! $ok) {
                    throw new \RuntimeException('cache read-back failed');
                }
            }),
            'storage' => $this->probe(function () {
                $f = 'health-'.bin2hex(random_bytes(4)).'.tmp';
                Storage::disk('local')->put($f, 'ok');
                Storage::disk('local')->delete($f);
            }),
        ];
        $ok = ! in_array('fail', $checks, true);

        $details = [];
        if ($checks['database'] === 'ok') {
            try {
                $details['queue_waiting'] = (int) DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('available_at');
                $details['queue_oldest_wait_seconds'] = $oldest ? max(0, time() - (int) $oldest) : 0;
                $details['failed_jobs'] = (int) DB::table('failed_jobs')->count();
            } catch (Throwable) {
                $details['queue'] = 'unavailable';
            }
        }
        $beat = $checks['cache'] === 'ok' ? Cache::get(self::HEARTBEAT_KEY) : null;
        $details['scheduler_last_seen_seconds_ago'] = $beat ? max(0, time() - (int) $beat) : null;

        return ['ok' => $ok, 'checks' => $checks, 'details' => $details];
    }

    private function probe(callable $fn): string
    {
        try {
            $fn();

            return 'ok';
        } catch (Throwable) {
            return 'fail'; // never leak the exception text on a public endpoint
        }
    }
}
