<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule rejection while building or changing an order (unavailable
 * item, expired combo, wrong branch ...). Rendered as a 422 with a stable
 * machine-readable `code` (see bootstrap/app.php).
 */
class OrderRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
