<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Realtime\AblyPublisher;
use App\Services\Realtime\PusherPublisher;
use App\Services\Realtime\Realtime;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    /** GET /realtime/config — which provider the browser should connect to (no secrets). */
    public function config(Request $request)
    {
        return response()->json(['data' => Realtime::publisher()->clientConfig() + ['branchId' => $request->attributes->get('branch_id')]]);
    }

    /** POST /realtime/auth {socket_id, channel_name} — Pusher/Reverb private-channel auth for the active branch only. */
    public function auth(Request $request)
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'max:100'], 'channel_name' => ['required', 'string', 'max:200']]);
        $publisher = Realtime::publisher();
        $branchId = $request->attributes->get('branch_id');

        if (! $publisher instanceof PusherPublisher || ! $branchId || $data['channel_name'] !== PusherPublisher::channel($branchId)) {
            return response()->json(['code' => 'forbidden_channel', 'message' => 'You can only subscribe to your active branch.'], 403);
        }

        return response()->json(['auth' => $publisher->authSignature($data['socket_id'], $data['channel_name'])]);
    }

    /** POST /realtime/ably-token — signed Ably TokenRequest limited to subscribing to the active branch. */
    public function ablyToken(Request $request)
    {
        $publisher = Realtime::publisher();
        $branchId = $request->attributes->get('branch_id');

        if (! $publisher instanceof AblyPublisher || ! $branchId) {
            return response()->json(['code' => 'realtime_unavailable', 'message' => 'Ably is not the configured provider.'], 422);
        }

        return response()->json($publisher->tokenRequest($branchId, 'user-'.$request->user()->id));
    }
}
