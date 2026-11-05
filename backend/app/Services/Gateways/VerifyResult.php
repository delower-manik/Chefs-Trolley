<?php

namespace App\Services\Gateways;

final class VerifyResult
{
    private function __construct(
        public readonly string $state, // paid | pending | failed
        public readonly ?string $trxId = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $error = null,
    ) {}

    public static function paid(string $trxId, ?int $amountMinor, ?string $currency = 'BDT'): self
    {
        return new self('paid', $trxId, $amountMinor, $currency);
    }

    public static function pending(?string $note = null): self
    {
        return new self('pending', error: $note);
    }

    public static function failed(string $error): self
    {
        return new self('failed', error: $error);
    }
}
