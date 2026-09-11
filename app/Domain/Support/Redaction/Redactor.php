<?php

namespace App\Domain\Support\Redaction;

/**
 * Value-based scrubbing of credential material. Secret values are
 * collected from the credential payload and replaced wherever they
 * appear in strings or arrays bound for sync run summaries and logs —
 * including when an adapter accidentally embeds one in a warning.
 * Deliberately biased towards over-redaction: every payload value of
 * eight characters or more is treated as secret, so a long region id
 * can vanish from a warning. That is safer than the alternative.
 */
final class Redactor
{
    private const REPLACEMENT = '[redacted]';

    /** @var list<string> */
    private readonly array $secrets;

    /**
     * @param  array<string, mixed>  $payload  the decrypted credential payload
     */
    public function __construct(array $payload)
    {
        $secrets = $this->collectSecrets($payload);

        // Longest first, so replacing a short key-named secret that is a
        // substring of a longer one cannot leave readable residue behind.
        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->secrets = $secrets;
    }

    public function message(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::REPLACEMENT, $text);
        }

        return $text;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function array(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                is_array($value) => $this->array($value),
                is_string($value) => $this->message($value),
                is_scalar($value) => $this->scrubScalar($value),
                default => $value,
            };
        }

        return $data;
    }

    /**
     * Scrubs a non-string scalar while keeping its type; only a value
     * that actually matched a secret comes back as a string.
     */
    private function scrubScalar(int|float|bool $value): int|float|bool|string
    {
        $raw = (string) $value;
        $scrubbed = $this->message($raw);

        return $scrubbed === $raw ? $value : $scrubbed;
    }

    /**
     * Every leaf value of the payload is a candidate secret. Long values
     * are always redacted; short values only when their key names a
     * secret, so harmless words like a region code survive in warnings.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function collectSecrets(array $payload): array
    {
        $secrets = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $secrets = [...$secrets, ...$this->collectSecrets($value)];

                continue;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $value = (string) $value;

            if ($value === '') {
                continue;
            }

            if (strlen($value) >= 8 || (is_string($key) && $this->namesSecret($key))) {
                $secrets[] = $value;
            }
        }

        return array_values(array_unique($secrets));
    }

    private function namesSecret(string $key): bool
    {
        return (bool) preg_match('/secret|token|password|passwd|credential|api[-_]?key|consumer/i', $key);
    }
}
