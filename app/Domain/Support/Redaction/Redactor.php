<?php

namespace App\Domain\Support\Redaction;

/**
 * Value-based scrubbing of credential material. Secret values are
 * collected from the credential payload and replaced wherever they
 * appear in strings or arrays bound for sync run summaries and logs —
 * including when an adapter accidentally embeds one in a warning.
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
        $this->secrets = $this->collectSecrets($payload);
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
            $data[$key] = is_array($value)
                ? $this->array($value)
                : (is_string($value) ? $this->message($value) : $value);
        }

        return $data;
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
