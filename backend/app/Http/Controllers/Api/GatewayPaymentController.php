<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentSession;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayPayments;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Staff side of online payments: start one, watch it, check it now, or (Bangla QR only) confirm it by hand. */
class GatewayPaymentController extends Controller
{
    public function __construct(private GatewayPayments $payments, private GatewayManager $gateways) {}

    /** GET /gateway-payments/options — which online methods this till can offer right now. */
    public function options()
    {
        $this->gateways->ensureCatalog();
        $out = [];
        foreach (GatewayManager::DRIVERS as $key => $class) {
            if ($this->gateways->usableConfig($key) !== null) {
                $out[] = ['key' => $key, 'label' => $class::label(), 'manual' => $class::manual()];
            }
        }

        return response()->json(['data' => $out]);
    }

    /** POST /orders/{order}/gateway-payments {gateway, amount?} */
    public function store(Request $request, Order $order)
    {
        Gate::authorize('view', $order);
        $data = $request->validate([
            'gateway' => ['required', 'in:'.implode(',', array_keys(GatewayManager::DRIVERS))],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:10000000'],
        ]);

        $result = $this->payments->start(
            $order, $data['gateway'], isset($data['amount']) ? Money::toMinor($data['amount']) : null, $request->user()->id, url('/api/v1'),
        );
        if (is_string($result)) {
            return response()->json(['code' => 'gateway_unavailable', 'message' => $result], 422);
        }

        $this->recordAudit($request, 'gateway_payment.started', $result, ['order' => $order->code, 'gateway' => $data['gateway'], 'amount' => (float) $result->amount]);

        return response()->json(['data' => $this->serialize($result)], 201);
    }

    /** GET /gateway-payments/{session} — what the till polls. Cheap: does not call the provider. */
    public function show(PaymentSession $session)
    {
        Gate::authorize('view', $session->order);

        return response()->json(['data' => $this->serialize($session)]);
    }

    /** POST /gateway-payments/{session}/check — ask the provider right now (covers a missed callback). */
    public function check(PaymentSession $session)
    {
        Gate::authorize('view', $session->order);

        return response()->json(['data' => $this->serialize($this->payments->refresh($session))]);
    }

    /** POST /gateway-payments/{session}/confirm {transaction_id} — Bangla QR only. */
    public function confirm(Request $request, PaymentSession $session)
    {
        Gate::authorize('view', $session->order);
        $data = $request->validate(['transaction_id' => ['required', 'string', 'min:4', 'max:60']]);

        $driver = $this->gateways->driver($session->gateway_key);
        if (! $driver || ! $driver::manual()) {
            return response()->json(['code' => 'not_manual', 'message' => 'This payment is confirmed by the provider automatically; use "Check now" instead.'], 422);
        }
        if (! $session->isOpen()) {
            return response()->json(['code' => 'session_closed', 'message' => "This payment request is {$session->status}."], 422);
        }

        return response()->json(['data' => $this->serialize($this->payments->confirmManually($session, $data['transaction_id'], $request->user()->id, $request->user()->name))]);
    }

    /** POST /gateway-payments/{session}/cancel */
    public function cancel(Request $request, PaymentSession $session)
    {
        Gate::authorize('view', $session->order);
        $session = $this->payments->cancel($session);
        $this->recordAudit($request, 'gateway_payment.cancelled', $session, ['status' => $session->status]);

        return response()->json(['data' => $this->serialize($session)]);
    }

    private function serialize(PaymentSession $s): array
    {
        return [
            'id' => $s->id, 'orderId' => $s->order_id, 'gateway' => $s->gateway_key, 'amount' => (float) $s->amount, 'status' => $s->status,
            'redirectUrl' => $s->isOpen() ? $s->redirect_url : null, 'qrPayload' => $s->isOpen() ? $s->qr_payload : null,
            'transactionId' => $s->provider_trx, 'note' => $s->note, 'expiresAt' => $s->expires_at, 'settledAt' => $s->settled_at,
        ];
    }
}
