<?php

namespace App\Domain\Providers\Exceptions;

use RuntimeException;
use Throwable;

class ProviderException extends RuntimeException
{
    /**
     * A sanitized message safe for sync run summaries and logs. Provider
     * exceptions must never carry raw payloads or credential material.
     */
    final public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
