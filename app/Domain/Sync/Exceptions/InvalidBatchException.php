<?php

namespace App\Domain\Sync\Exceptions;

use App\Domain\Providers\Exceptions\ProviderException;

/**
 * A batch violated a canonical invariant (non-canonical category, a
 * duplicate external id or source reference, a fact referencing
 * services outside this run's inventory, or a completeness flag that
 * contradicts the batch content). The run fails with a sanitized
 * message; nothing is persisted.
 */
final class InvalidBatchException extends ProviderException {}
