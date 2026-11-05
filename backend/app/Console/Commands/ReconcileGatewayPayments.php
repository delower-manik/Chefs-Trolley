<?php

namespace App\Console\Commands;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayPayments;
use Illuminate\Console\Command;

class ReconcileGatewayPayments extends Command
{
    protected $signature = 'gateways:reconcile';

    protected $description = 'Ask providers about open online payments (missed callbacks) and expire stale ones';

    public function handle(GatewayPayments $payments, GatewayManager $gateways): int
    {
        PaymentSession::whereIn('status', ['created', 'pending'])->where('created_at', '>', now()->subDay())->orderBy('created_at')->limit(100)->get()
            ->each(function (PaymentSession $s) use ($payments, $gateways) {
                $driver = $gateways->driver($s->gateway_key);
                if ($driver && ! $driver::manual()) {
                    $payments->refresh($s);
                }
            });

        // After the provider has had a last chance to say "paid", anything past its time limit is closed.
        $payments->expireOld();

        return self::SUCCESS;
    }
}
