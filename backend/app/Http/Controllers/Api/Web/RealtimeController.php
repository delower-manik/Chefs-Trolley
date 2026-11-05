<?php

namespace App\Http\Controllers\Api\Web;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Realtime\PusherPublisher;
use App\Services\Realtime\Realtime;
use App\Support\OrderStage;
use Illuminate\Http\Request;

/** Live order tracking for the website. Push only on Pusher/Reverb; everything else polls /orders/{id}/status. */
class RealtimeController extends Controller
{
    /** GET /web/realtime/config — public connection settings (never secrets). {driver:"none"} means "poll". */
    public function config()
    {
        $publisher = Realtime::publisher();

        return response()->json(['data' => $publisher instanceof PusherPublisher ? $publisher->clientConfig() : ['driver' => 'none']]);
    }

    /** POST /web/realtime/auth {socket_id, channel_name} — a customer may subscribe only to the channel of their OWN web order. */
    public function auth(Request $request)
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'max:100', 'regex:/^[\d.]+$/'], 'channel_name' => ['required', 'string', 'max:200']]);
        $publisher = Realtime::publisher();
        $customer = $request->attributes->get('customer');

        $orderId = str_starts_with($data['channel_name'], 'private-order.') ? substr($data['channel_name'], strlen('private-order.')) : null;
        $owns = $orderId && Order::where('id', $orderId)->where('customer_id', $customer->id)->where('channel', 'web')->exists();

        if (! $publisher instanceof PusherPublisher || ! $owns) {
            return response()->json(['code' => 'forbidden_channel', 'message' => 'You can only follow your own orders.'], 403);
        }

        return response()->json(['auth' => $publisher->authSignature($data['socket_id'], $data['channel_name'])]);
    }

    /** GET /web/orders/{order}/status — a few bytes; answers 304 when nothing changed (the polling fallback and the post-ping refresh). */
    public function status(Request $request, string $order)
    {
        $o = Order::where('id', $order)->where('customer_id', $request->attributes->get('customer')->id)->where('channel', 'web')->firstOrFail();
        $stage = OrderStage::of($o);
        $etag = '"'.md5($o->id.'|'.$stage.'|'.$o->status.'|'.$o->payment_state.'|'.$o->updated_at?->getTimestamp()).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json(['data' => ['id' => $o->id, 'stage' => $stage, 'status' => $o->status, 'paymentState' => $o->payment_state]])->header('ETag', $etag);
    }
}
