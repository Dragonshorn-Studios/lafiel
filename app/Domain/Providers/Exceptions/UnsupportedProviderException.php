<?php

namespace App\Domain\Providers\Exceptions;

/**
 * No adapter is registered for the account's provider key.
 */
final class UnsupportedProviderException extends ProviderException {}
