<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentSession;
use App\Services\Gateways\GatewayPayments;
use Illuminate\Http\Request;

/**
 * Where providers send the customer back (and, for some, a server-to-server notification). Anything in the request
 * is untrusted: it only tells us WHEN to ask the provider, and the provider's own answer decides what is credited.
 */
class GatewayCallbackController extends Controller
{
    public function __construct(private GatewayPayments $payments) {}

    /** GET|POST /payment-callbacks/{gateway}/{session}/{token}[/ipn] */
    public function handle(Request $request, string $gateway, string $session, string $token, ?string $ipn = null)
    {
        $row = PaymentSession::where('id', $session)->where('gateway_key', $gateway)->first();
        if (! $row || ! hash_equals($row->callbackToken(), $token)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $row = $this->payments->refresh($row, $request->all());

        if ($ipn !== null) {
            return response()->json(['status' => $row->status]);
        }

        // The customer's browser: send them back to the app, which shows the result from our own record.
        // Website orders go back to the customer website's order page; staff-started payments go back to the admin console.
        if ($row->order?->channel === 'web') {
            $web = rtrim((string) config('gateways.website_url'), '/');

            return redirect()->away("{$web}/orders/{$row->order_id}?payment={$row->status}", 303);
        }
        $base = rtrim((string) config('gateways.return_url'), '/');

        return redirect()->away("{$base}/payment-result?session={$row->id}&status={$row->status}", 303);
    }
}
