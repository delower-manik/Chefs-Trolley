<?php

namespace App\Services\Gateways\Drivers;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayDriver;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\InitResult;
use App\Services\Gateways\VerifyResult;
use App\Support\Money;
use Illuminate\Support\Facades\Http;

/** bKash Tokenized Checkout (grant token → create → status / execute). Written from the public docs; not run against a live merchant account. */
class Bkash implements GatewayDriver
{
    public static function key(): string
    {
        return 'bkash';
    }

    public static function label(): string
    {
        return 'bKash';
    }

    public static function manual(): bool
    {
        return false;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'app_key', 'label' => 'App key', 'secret' => false, 'required' => true],
            ['key' => 'app_secret', 'label' => 'App secret', 'secret' => true, 'required' => true],
            ['key' => 'username', 'label' => 'Username', 'secret' => false, 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'secret' => true, 'required' => true],
            ['key' => 'sandbox', 'label' => 'Use sandbox (testing)', 'secret' => false, 'required' => false, 'type' => 'bool'],
        ];
    }

    private function base(array $c): string
    {
        return GatewayManager::truthy($c['sandbox'] ?? false)
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';
    }

    /** @return array<string, string> */
    private function headers(array $c): array
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->withHeaders(['username' => $c['username'], 'password' => $c['password']])
            ->post($this->base($c).'/token/grant', ['app_key' => $c['app_key'], 'app_secret' => $c['app_secret']]);
        $token = $res->json('id_token');
        if (! $res->successful() || ! is_string($token)) {
            throw new \RuntimeException('bKash token grant failed: '.($res->json('statusMessage') ?: 'HTTP '.$res->status()));
        }

        return ['Authorization' => $token, 'X-APP-Key' => $c['app_key']];
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->withHeaders($this->headers($config))->post($this->base($config).'/create', [
            'mode' => '0011', 'payerReference' => $customer['phone'], 'callbackURL' => $callbackUrl,
            'amount' => number_format((float) $session->amount, 2, '.', ''), 'currency' => 'BDT', 'intent' => 'sale',
            'merchantInvoiceNumber' => substr($customer['invoice'].'-'.substr($session->id, 0, 8), 0, 255),
        ]);

        if (! $res->successful() || (string) $res->json('statusCode') !== '0000' || ! $res->json('paymentID') || ! is_string($res->json('bkashURL'))) {
            return InitResult::failed('bKash did not start the payment: '.($res->json('statusMessage') ?: 'unexpected response'));
        }

        return InitResult::ok((string) $res->json('paymentID'), $res->json('bkashURL'));
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        $headers = $this->headers($config);
        $hint = strtolower((string) ($callback['status'] ?? ''));
        $paymentId = (string) $session->provider_ref; // never the id from the callback

        $query = fn () => Http::timeout(20)->acceptJson()->asJson()->withHeaders($headers)->post($this->base($config).'/payment/status', ['paymentID' => $paymentId]);
        $state = fn ($r) => (string) ($r->json('transactionStatus') ?: '');

        $status = $query();

        // The customer approved on bKash's page: the money is only taken once we Execute the payment.
        if ($status->successful() && $state($status) === 'Initiated' && $hint === 'success') {
            $exec = Http::timeout(30)->acceptJson()->asJson()->withHeaders($headers)->post($this->base($config).'/execute', ['paymentID' => $paymentId]);
            if ($exec->successful() && (string) $exec->json('statusCode') === '0000' && $state($exec) === 'Completed') {
                return VerifyResult::paid((string) $exec->json('trxID'), Money::toMinor($exec->json('amount')), (string) ($exec->json('currency') ?: 'BDT'));
            }
            $status = $query(); // execute can fail because it already ran (the callback fired twice): ask again
        }

        if ($status->successful() && $state($status) === 'Completed') {
            return VerifyResult::paid((string) $status->json('trxID'), Money::toMinor($status->json('amount')), (string) ($status->json('currency') ?: 'BDT'));
        }
        if (in_array($hint, ['cancel', 'failure'], true) || in_array($state($status), ['Failed', 'Cancelled'], true)) {
            return VerifyResult::failed($hint === 'cancel' ? 'The customer cancelled on bKash.' : 'bKash reports the payment did not go through.');
        }

        return VerifyResult::pending();
    }
}
