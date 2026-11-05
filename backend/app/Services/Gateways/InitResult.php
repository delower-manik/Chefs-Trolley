<?php

namespace App\Services\Gateways;

final class InitResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $providerRef = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $qrPayload = null,
        public readonly array $payload = [],
        public readonly ?string $error = null,
    ) {}

    public static function ok(?string $providerRef, ?string $redirectUrl = null, ?string $qrPayload = null, array $payload = []): self
    {
        return new self(true, $providerRef, $redirectUrl, $qrPayload, $payload);
    }

    public static function failed(string $error): self
    {
        return new self(false, error: $error);
    }
}
