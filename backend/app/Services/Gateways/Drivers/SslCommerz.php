<?php

namespace App\Services\Gateways\Drivers;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayDriver;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\InitResult;
use App\Services\Gateways\VerifyResult;
use App\Support\Money;
use Illuminate\Support\Facades\Http;

/** SSLCommerz hosted checkout (v4 session API + transaction-id validation). Written from the public docs; not run against a live merchant account. */
class SslCommerz implements GatewayDriver
{
    public static function key(): string
    {
        return 'sslcommerz';
    }

    public static function label(): string
    {
        return 'SSLCommerz';
    }

    public static function manual(): bool
    {
        return false;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'store_id', 'label' => 'Store ID', 'secret' => false, 'required' => true],
            ['key' => 'store_password', 'label' => 'Store password', 'secret' => true, 'required' => true],
            ['key' => 'sandbox', 'label' => 'Use sandbox (testing)', 'secret' => false, 'required' => false, 'type' => 'bool'],
        ];
    }

    private function base(array $c): string
    {
        return GatewayManager::truthy($c['sandbox'] ?? false) ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $res = Http::timeout(20)->asForm()->acceptJson()->post($this->base($config).'/gwprocess/v4/api.php', [
            'store_id' => $config['store_id'], 'store_passwd' => $config['store_password'],
            'total_amount' => number_format((float) $session->amount, 2, '.', ''), 'currency' => 'BDT', 'tran_id' => $session->id,
            'success_url' => $callbackUrl, 'fail_url' => $callbackUrl, 'cancel_url' => $callbackUrl, 'ipn_url' => $callbackUrl.'/ipn',
            'cus_name' => $customer['name'], 'cus_email' => $customer['email'], 'cus_add1' => 'Dhaka', 'cus_city' => 'Dhaka',
            'cus_country' => 'Bangladesh', 'cus_phone' => $customer['phone'], 'shipping_method' => 'NO', 'num_of_item' => 1,
            'product_name' => $customer['description'], 'product_category' => 'Food', 'product_profile' => 'general',
        ]);

        $url = $res->json('GatewayPageURL');
        if (! $res->successful() || strtoupper((string) $res->json('status')) !== 'SUCCESS' || ! is_string($url) || $url === '') {
            return InitResult::failed('SSLCommerz did not start the payment: '.($res->json('failedreason') ?: 'unexpected response'));
        }

        return InitResult::ok($session->id, $url, null, ['sessionkey' => $res->json('sessionkey')]);
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        $res = Http::timeout(20)->acceptJson()->get($this->base($config).'/validator/api/merchantTransIDvalidationAPI.php', [
            'tran_id' => $session->provider_ref ?: $session->id, 'store_id' => $config['store_id'], 'store_passwd' => $config['store_password'], 'format' => 'json',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('SSLCommerz validation failed: HTTP '.$res->status());
        }

        $elements = (array) $res->json('element', []);
        foreach ($elements as $e) {
            if (in_array(strtoupper((string) ($e['status'] ?? '')), ['VALID', 'VALIDATED'], true)) {
                return VerifyResult::paid((string) ($e['bank_tran_id'] ?? $e['val_id'] ?? $session->id), Money::toMinor($e['amount'] ?? null), (string) ($e['currency_type'] ?? 'BDT'));
            }
        }
        foreach ($elements as $e) {
            if (in_array(strtoupper((string) ($e['status'] ?? '')), ['FAILED', 'CANCELLED', 'EXPIRED', 'UNATTEMPTED'], true)) {
                return VerifyResult::failed('SSLCommerz reports the payment '.strtolower((string) $e['status']).'.');
            }
        }

        return VerifyResult::pending();
    }
}
