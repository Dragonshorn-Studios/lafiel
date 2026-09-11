<?php

namespace App\Domain\Providers\Exceptions;

/**
 * The provider rejected the credentials. Never retried: bad credentials
 * are a permanent condition until a human fixes them.
 */
final class InvalidCredentialsException extends ProviderException {}
