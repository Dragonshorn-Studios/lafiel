<?php

namespace App\Domain\Providers\Exceptions;

/**
 * A transient provider failure: HTTP 429, 5xx, or a timeout. The only
 * exception class the retry policy is allowed to retry, with bounded
 * exponential backoff and jitter.
 */
final class TransientProviderException extends ProviderException {}
