<?php

namespace App\Services\Xero;

use RuntimeException;

/**
 * kind: validation (fix data, don't retry) · auth (reconnect Xero) · rate_limited · transient (retry) · not_connected
 */
class XeroException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'transient', public readonly ?int $retryAfter = null, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, ['transient', 'rate_limited'], true);
    }
}
