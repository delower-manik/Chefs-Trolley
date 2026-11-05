<?php

namespace App\Services\Gateways;

use App\Models\PaymentSession;

interface GatewayDriver
{
    public static function key(): string;

    public static function label(): string;

    /** @return list<array{key: string, label: string, secret: bool, required: bool, type?: string}> credential fields shown on the settings screen */
    public static function fields(): array;

    /** True when the provider can't tell us a payment happened (Bangla QR): a person confirms it. */
    public static function manual(): bool;

    /** Ask the provider to start a payment for the session. $callbackUrl is where the provider sends the customer / its notification. */
    public function initiate(PaymentSession $session, array $config, string $callbackUrl, array $customer): InitResult;

    /**
     * Ask the provider — server to server — what really happened. Anything in $callback is a hint at best:
     * the session's own stored reference decides what is queried, and the provider's answer decides the result.
     */
    public function verify(PaymentSession $session, array $config, array $callback): VerifyResult;
}
