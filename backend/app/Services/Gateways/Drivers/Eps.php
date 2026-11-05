<?php

namespace App\Services\Gateways\Drivers;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayDriver;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\InitResult;
use App\Services\Gateways\VerifyResult;
use App\Support\Money;
use Illuminate\Support\Facades\Http;

/**
 * EPS (Easy Payment System) hosted checkout: token → initialise → merchant-transaction status. This is the LOWEST
 * confidence driver: written from memory of the merchant API, with no documentation to hand, and never run against
 * the provider. Treat it as a starting point and check every field against EPS's current merchant docs.
 */
class Eps implements GatewayDriver
{
    public static function key(): string
    {
        return 'eps';
    }

    public static function label(): string
    {
        return 'EPS';
    }

    public static function manual(): bool
    {
        return false;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'username', 'label' => 'Username', 'secret' => false, 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'secret' => true, 'required' => true],
            ['key' => 'hash_key', 'label' => 'Hash key', 'secret' => true, 'required' => true],
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false, 'required' => true],
            ['key' => 'store_id', 'label' => 'Store ID', 'secret' => false, 'required' => true],
            ['key' => 'sandbox', 'label' => 'Use sandbox (testing)', 'secret' => false, 'required' => false, 'type' => 'bool'],
        ];
    }

    private function base(array $c): string
    {
        return GatewayManager::truthy($c['sandbox'] ?? false) ? 'https://sandboxpgapi.eps.com.bd' : 'https://pgapi.eps.com.bd';
    }

    private function hash(string $value, string $key): string
    {
        return base64_encode(hash_hmac('sha512', $value, $key, true));
    }

    private function token(array $c): string
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->withHeaders(['x-hash' => $this->hash($c['username'], $c['hash_key'])])
            ->post($this->base($c).'/v1/Auth/GetToken', ['userName' => $c['username'], 'password' => $c['password']]);
        if (! $res->successful() || ! is_string($res->json('token'))) {
            throw new \RuntimeException('EPS token request failed: HTTP '.$res->status());
        }

        return $res->json('token');
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->withToken($this->token($config))->withHeaders(['x-hash' => $this->hash($session->id, $config['hash_key'])])
            ->post($this->base($config).'/v1/EPSEngine/InitializeEPS', [
                'merchantId' => $config['merchant_id'], 'storeId' => $config['store_id'], 'CustomerOrderId' => $customer['invoice'],
                'merchantTransactionId' => $session->id, 'transactionTypeId' => 1, 'financialEntityId' => 0, 'transitionStatusId' => 0,
                'totalAmount' => (float) $session->amount, 'ipAddress' => request()->ip() ?: '127.0.0.1', 'version' => '1',
                'successUrl' => $callbackUrl, 'failUrl' => $callbackUrl, 'cancelUrl' => $callbackUrl,
                'customerName' => $customer['name'], 'customerEmail' => $customer['email'], 'CustomerAddress' => 'Dhaka', 'CustomerAddress2' => 'Dhaka',
                'CustomerCity' => 'Dhaka', 'CustomerState' => 'Dhaka', 'CustomerPostcode' => '1000', 'CustomerCountry' => 'BD', 'CustomerPhone' => $customer['phone'],
                'ProductName' => $customer['description'], 'ProductProfile' => 'general', 'ProductCategory' => 'Food', 'NoOfItem' => '1',
            ]);

        $url = $res->json('RedirectURL');
        if (! $res->successful() || ! is_string($url) || $url === '') {
            return InitResult::failed('EPS did not start the payment: '.($res->json('ErrorMessage') ?: 'unexpected response'));
        }

        return InitResult::ok($session->id, $url, null, ['eps_transaction' => $res->json('TransactionId')]);
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        $res = Http::timeout(20)->acceptJson()->withToken($this->token($config))->withHeaders(['x-hash' => $this->hash($session->id, $config['hash_key'])])
            ->get($this->base($config).'/v1/EPSEngine/CheckMerchantTransactionStatus', ['merchantTransactionId' => $session->id]);
        if (! $res->successful()) {
            throw new \RuntimeException('EPS status check failed: HTTP '.$res->status());
        }

        return match (strtolower((string) $res->json('Status'))) {
            'success' => VerifyResult::paid((string) ($res->json('FinancialEntityTransactionId') ?: $res->json('EpsTransactionId') ?: $res->json('TransactionId') ?: $session->id), Money::toMinor($res->json('TotalAmount')), 'BDT'),
            'failed', 'cancel', 'cancelled' => VerifyResult::failed('EPS reports the payment '.strtolower((string) $res->json('Status')).'.'),
            default => VerifyResult::pending(),
        };
    }
}
