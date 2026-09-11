<?php

namespace App\Domain\Sync\Exceptions;

use App\Domain\Providers\Exceptions\ProviderException;

/**
 * A batch violated a canonical invariant (unknown category, a known
 * amount without currency, a duplicate identity). The run fails with a
 * sanitized message; nothing is persisted.
 */
final class InvalidBatchException extends ProviderException {}
