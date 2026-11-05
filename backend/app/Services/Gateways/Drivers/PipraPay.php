<?php

namespace App\Services\Gateways\Drivers;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayDriver;
use App\Services\Gateways\InitResult;
use App\Services\Gateways\VerifyResult;
use App\Support\Money;
use Illuminate\Support\Facades\Http;

/**
 * Pipra Pay is installed on the restaurant's own server, so the base URL is a setting. Written from memory of its
 * public API (create-charge / verify-payments) — LOW confidence, not run against a real install.
 */
class PipraPay implements GatewayDriver
{
    public static function key(): string
    {
        return 'piprapay';
    }

    public static function label(): string
    {
        return 'Pipra Pay';
    }

    public static function manual(): bool
    {
        return false;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Your Pipra Pay URL (https://…)', 'secret' => false, 'required' => true],
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true, 'required' => true],
        ];
    }

    private function base(array $c): string
    {
        $url = rtrim((string) $c['base_url'], '/');
        if (! preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Pipra Pay URL must start with https://');
        }

        return $url;
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->withHeaders(['mh-piprapay-api-key' => $config['api_key']])->post($this->base($config).'/api/create-charge', [
            'full_name' => $customer['name'], 'email_mobile' => $customer['phone'], 'amount' => number_format((float) $session->amount, 2, '.', ''),
            'metadata' => ['session' => $session->id, 'invoice' => $customer['invoice']], 'redirect_url' => $callbackUrl, 'return_type' => 'GET',
            'cancel_url' => $callbackUrl, 'webhook_url' => $callbackUrl.'/ipn', 'currency' => 'BDT',
        ]);

        $ref = $res->json('pp_id');
        $url = $res->json('pp_url');
        if (! $res->successful() || ! $ref || ! is_string($url)) {
            return InitResult::failed('Pipra Pay did not start the payment: '.($res->json('message') ?: 'unexpected response'));
        }

        return InitResult::ok((string) $ref, $url);
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->withHeaders(['mh-piprapay-api-key' => $config['api_key']])
            ->post($this->base($config).'/api/verify-payments', ['pp_id' => $session->provider_ref]);
        if (! $res->successful()) {
            throw new \RuntimeException('Pipra Pay verification failed: HTTP '.$res->status());
        }

        return match (strtolower((string) $res->json('status'))) {
            'completed', 'success', 'paid' => VerifyResult::paid((string) ($res->json('transaction_id') ?: $session->provider_ref), Money::toMinor($res->json('amount')), (string) ($res->json('currency') ?: 'BDT')),
            'failed', 'canceled', 'cancelled' => VerifyResult::failed('Pipra Pay reports the payment '.strtolower((string) $res->json('status')).'.'),
            default => VerifyResult::pending(),
        };
    }
}
