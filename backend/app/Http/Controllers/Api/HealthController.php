<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Health;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    /**
     * GET /health — public. Status only (200 ok / 503 when the database, cache or storage fails).
     * Queue backlog and scheduler age are added only when the caller sends the X-Health-Token from HEALTH_TOKEN.
     */
    public function show(Request $request, Health $health)
    {
        $r = $health->run();
        $body = ['status' => $r['ok'] ? 'ok' : 'degraded', 'checks' => $r['checks']];
        $token = (string) config('app.health_token');
        if ($token !== '' && hash_equals($token, (string) $request->header('X-Health-Token'))) {
            $body['details'] = $r['details'];
        }

        return response()->json($body, $r['ok'] ? 200 : 503);
    }
}
