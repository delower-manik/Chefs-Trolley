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
 * Nagad checkout (initialise → complete → verify), including its RSA-encrypted and signed request bodies.
 * Written from memory of the merchant integration guide. The crypto round-trip is tested with locally generated
 * keys but NOT against Nagad: MEDIUM-LOW confidence. Check field names and the key format in Nagad's sandbox first.
 */
class Nagad implements GatewayDriver
{
    public static function key(): string
    {
        return 'nagad';
    }

    public static function label(): string
    {
        return 'Nagad';
    }

    public static function manual(): bool
    {
        return false;
    }

    public static function fields(): array
    {
        return [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false, 'required' => true],
            ['key' => 'merchant_number', 'label' => 'Merchant number', 'secret' => false, 'required' => true],
            ['key' => 'merchant_private_key', 'label' => 'Merchant private key (PEM or base64)', 'secret' => true, 'required' => true, 'type' => 'textarea'],
            ['key' => 'pg_public_key', 'label' => 'Nagad public key (PEM or base64)', 'secret' => true, 'required' => true, 'type' => 'textarea'],
            ['key' => 'sandbox', 'label' => 'Use sandbox (testing)', 'secret' => false, 'required' => false, 'type' => 'bool'],
        ];
    }

    private function base(array $c): string
    {
        return GatewayManager::truthy($c['sandbox'] ?? false)
            ? 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs'
            : 'https://api.mynagad.com/api/dfs';
    }

    private function pem(string $key, string $type): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN {$type}-----\n".chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")."-----END {$type}-----";
    }

    private function encrypt(array $data, array $c): string
    {
        $pub = openssl_pkey_get_public($this->pem($c['pg_public_key'], 'PUBLIC KEY'));
        if (! $pub || ! openssl_public_encrypt(json_encode($data), $out, $pub, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Nagad: could not encrypt with the Nagad public key.');
        }

        return base64_encode($out);
    }

    private function decrypt(string $b64, array $c): array
    {
        $priv = openssl_pkey_get_private($this->pem($c['merchant_private_key'], 'PRIVATE KEY'));
        if (! $priv || ! openssl_private_decrypt((string) base64_decode($b64, true), $out, $priv, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Nagad: could not decrypt the response with the merchant private key.');
        }

        return (array) json_decode($out, true);
    }

    private function sign(array $data, array $c): string
    {
        $priv = openssl_pkey_get_private($this->pem($c['merchant_private_key'], 'PRIVATE KEY'));
        if (! $priv || ! openssl_sign(json_encode($data), $sig, $priv, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Nagad: could not sign with the merchant private key.');
        }

        return base64_encode($sig);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-KM-IP-V4' => request()->ip() ?: '127.0.0.1', 'X-KM-Client-Type' => 'PC_WEB', 'X-KM-Api-Version' => 'v-0.2.0'];
    }

    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult
    {
        $orderId = substr(str_replace('-', '', $session->id), 0, 30); // Nagad order ids are short alphanumerics
        $sensitive = ['merchantId' => $config['merchant_id'], 'datetime' => now('Asia/Dhaka')->format('YmdHis'), 'orderId' => $orderId, 'challenge' => bin2hex(random_bytes(20))];

        $init = Http::timeout(20)->acceptJson()->asJson()->withHeaders($this->headers())
            ->post($this->base($config)."/check-out/initialize/{$config['merchant_id']}/{$orderId}", [
                'accountNumber' => $config['merchant_number'], 'dateTime' => $sensitive['datetime'],
                'sensitiveData' => $this->encrypt($sensitive, $config), 'signature' => $this->sign($sensitive, $config),
            ]);
        if (! $init->successful() || ! is_string($init->json('sensitiveData'))) {
            return InitResult::failed('Nagad did not accept the payment: '.($init->json('message') ?: 'unexpected response'));
        }
        $reply = $this->decrypt($init->json('sensitiveData'), $config);
        $refId = $reply['paymentReferenceId'] ?? null;
        if (! $refId || ! isset($reply['challenge'])) {
            return InitResult::failed('Nagad returned an unexpected initialise response.');
        }

        $order = ['merchantId' => $config['merchant_id'], 'orderId' => $orderId, 'currencyCode' => '050', 'amount' => number_format((float) $session->amount, 2, '.', ''), 'challenge' => $reply['challenge']];
        $complete = Http::timeout(20)->acceptJson()->asJson()->withHeaders($this->headers())->post($this->base($config)."/check-out/complete/{$refId}", [
            'sensitiveData' => $this->encrypt($order, $config), 'signature' => $this->sign($order, $config),
            'merchantCallbackURL' => $callbackUrl, 'additionalMerchantInfo' => ['invoice' => $customer['invoice']],
        ]);
        $url = $complete->json('callBackUrl');
        if (! $complete->successful() || ! is_string($url) || strtolower((string) $complete->json('status')) !== 'success') {
            return InitResult::failed('Nagad did not start the payment: '.($complete->json('message') ?: 'unexpected response'));
        }

        return InitResult::ok((string) $refId, $url, null, ['order_id' => $orderId]);
    }

    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult
    {
        $res = Http::timeout(20)->acceptJson()->withHeaders($this->headers())->get($this->base($config).'/verify/payment/'.$session->provider_ref);
        if (! $res->successful()) {
            throw new \RuntimeException('Nagad verification failed: HTTP '.$res->status());
        }

        return match (strtolower((string) $res->json('status'))) {
            'success' => VerifyResult::paid((string) ($res->json('issuerPaymentRefNo') ?: $res->json('paymentRefId') ?: $session->provider_ref), Money::toMinor($res->json('amount')), 'BDT'),
            'failed', 'aborted', 'cancelled', 'cancel', 'invalidrequest', 'fraud', 'expired', 'unknownfailed' => VerifyResult::failed('Nagad reports the payment '.strtolower((string) $res->json('status')).'.'),
            default => VerifyResult::pending(),
        };
    }
}
