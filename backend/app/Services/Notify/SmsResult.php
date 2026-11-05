<?php

namespace App\Services\Notify;

class SmsResult
{
    public function __construct(
        public bool $ok,
        public ?string $messageId = null,
        public ?string $error = null,
        public bool $retryable = false, // network errors / 5xx / 429; false for bad number or credentials
    ) {}

    public static function sent(?string $id = null): self
    {
        return new self(true, $id);
    }

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, null, $error, $retryable);
    }
}
