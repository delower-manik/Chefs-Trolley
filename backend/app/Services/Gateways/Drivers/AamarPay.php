<?php

namespace App\Services\Gateways\Drivers;

use App\Models\PaymentSession;
use App\Services\Gateways\GatewayDriver;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\InitResult;
use App\Services\Gateways\VerifyResult;
use App\Support\Money;
use Illuminate\Support\Facades\Http;

/** aamarPay hosted checkout. Written from the public merchant docs; not run against a live merchant account. */
class AamarPay implements GatewayDriver
{
    public static function key(): string
    {
        return 'aamarpay';
    }

    public static function label(): string
    {
        return 'aamarPay';
    }

    public static function manual(): bool
    {
        return false;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'store_id', 'label' => 'Store ID', 'secret' => false, 'required' => true],
            ['key' => 'signature_key', 'label' => 'Signature key', 'secret' => true, 'required' => true],
            ['key' => 'sandbox', 'label' => 'Use sandbox (testing)', 'secret' => false, 'required' => false, 'type' => 'bool'],
        ];
    }

    private function base(array $c): string
    {
        return GatewayManager::truthy($c['sandbox'] ?? false) ? 'https://sandbox.aamarpay.com' : 'https://secure.aamarpay.com';
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $res = Http::timeout(20)->acceptJson()->asJson()->post($this->base($config).'/jsonpost.php', [
            'store_id' => $config['store_id'], 'signature_key' => $config['signature_key'], 'tran_id' => $session->id,
            'amount' => number_format((float) $session->amount, 2, '.', ''), 'currency' => 'BDT', 'desc' => $customer['description'],
            'cus_name' => $customer['name'], 'cus_email' => $customer['email'], 'cus_phone' => $customer['phone'],
            'cus_add1' => 'Dhaka', 'cus_city' => 'Dhaka', 'cus_country' => 'Bangladesh',
            'success_url' => $callbackUrl, 'fail_url' => $callbackUrl, 'cancel_url' => $callbackUrl, 'type' => 'json',
        ]);

        $url = $res->json('payment_url');
        if (! $res->successful() || ! GatewayManager::truthy($res->json('result')) || ! is_string($url)) {
            return InitResult::failed('aamarPay did not start the payment: '.$this->message($res->json()));
        }

        return InitResult::ok($session->id, $url);
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        $res = Http::timeout(20)->acceptJson()->get($this->base($config).'/api/v1/trxcheck/request.php', [
            'request_id' => $session->provider_ref ?: $session->id, 'store_id' => $config['store_id'],
            'signature_key' => $config['signature_key'], 'type' => 'json',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('aamarPay status check failed: HTTP '.$res->status());
        }

        return match (strtolower((string) $res->json('pay_status'))) {
            'successful' => VerifyResult::paid((string) ($res->json('pg_txnid') ?: $res->json('mer_txnid') ?: $session->id), Money::toMinor($res->json('amount')), (string) ($res->json('currency') ?: 'BDT')),
            'failed' => VerifyResult::failed('aamarPay reports the payment failed.'),
            default => VerifyResult::pending(),
        };
    }

    private function message(mixed $json): string
    {
        return is_array($json) ? (string) ($json['reason'] ?? $json['message'] ?? json_encode($json)) : 'no response';
    }
}
