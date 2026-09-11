<?php

namespace App\Domain\Sync\Retry;

use App\Domain\Providers\Exceptions\TransientProviderException;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Bounded exponential backoff with jitter for transient provider
 * failures (429, 5xx, timeout). Anything else — invalid credentials,
 * invalid batches, programming errors — propagates on the first throw.
 * Sleeping goes through the Sleep facade so tests can fake it.
 */
final class RetryPolicy
{
    private readonly int $maxAttempts;

    private readonly int $baseDelayMs;

    private readonly int $maxDelayMs;

    public function __construct(
        ?int $maxAttempts = null,
        ?int $baseDelayMs = null,
        ?int $maxDelayMs = null,
    ) {
        $this->maxAttempts = max(1, $maxAttempts ?? (int) config('sync.retry.max_attempts', 4));
        $this->baseDelayMs = max(0, $baseDelayMs ?? (int) config('sync.retry.base_delay_ms', 500));
        $this->maxDelayMs = max(0, $maxDelayMs ?? (int) config('sync.retry.max_delay_ms', 15000));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function execute(Closure $operation): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (TransientProviderException $exception) {
                if ($attempt >= $this->maxAttempts) {
                    throw $exception;
                }

                Sleep::for($this->delayFor($attempt))->milliseconds();
            }
        }
    }

    /**
     * Attempt N (1-based, the sleep before retry N+1) waits a random
     * half-to-full share of min(cap, base * 2^(N-1)).
     */
    private function delayFor(int $attempt): int
    {
        $ceiling = (int) min($this->maxDelayMs, $this->baseDelayMs * 2 ** ($attempt - 1));

        return random_int(
            (int) ceil($ceiling / 2),
            max(1, $ceiling),
        );
    }
}
