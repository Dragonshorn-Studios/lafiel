<?php

namespace App\Domain\Costs\Actions;

use App\Domain\Costs\Enums\Period;
use App\Domain\Costs\Models\SubscriptionPreset;
use App\Domain\Support\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Import subscription presets from a remote JSON catalog — the
 * explicit replacement for the old silent AI_PRESETS_URL merge.
 * Entries are upserted by key (the catalog wins for that key);
 * entries that are malformed or carry an unusable amount are skipped
 * and counted, never fatal.
 */
final class ImportSubscriptionPresets
{
    /**
     * @return array{imported: int, updated: int, skipped: int}
     *
     * @throws ValidationException when the catalog cannot be fetched or read
     */
    public function import(?string $url = null): array
    {
        $url = trim($url ?? (string) config('services.ai_presets_url'));

        if ($url === '') {
            throw ValidationException::withMessages([
                'url' => __('No catalog URL is configured. Set AI_PRESETS_URL or pass a URL.'),
            ]);
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            throw ValidationException::withMessages([
                'url' => __('The catalog URL must start with http:// or https://.'),
            ]);
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get($url);
        } catch (Throwable $exception) {
            // The user toast says the catalog was unreachable; the
            // underlying DNS/TLS/timeout cause stays in the logs.
            report($exception);

            throw ValidationException::withMessages([
                'url' => __('The catalog could not be reached.'),
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'url' => __('The catalog returned HTTP :status.', ['status' => $response->status()]),
            ]);
        }

        $catalog = $response->json();

        if (! is_array($catalog) || $catalog === []) {
            throw ValidationException::withMessages([
                'url' => __('The catalog response is empty or not a JSON object.'),
            ]);
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        // All-or-nothing: the confirmed overwrite must never leave a
        // half-applied catalog behind on a mid-import failure.
        DB::transaction(function () use (&$imported, &$updated, &$skipped, $catalog): void {
            foreach ($catalog as $entry) {
                $attributes = is_array($entry) ? $this->normalize($entry) : null;

                if ($attributes === null) {
                    $skipped++;

                    continue;
                }

                $preset = SubscriptionPreset::query()->updateOrCreate(
                    ['key' => $attributes['key']],
                    $attributes,
                );

                if ($preset->wasRecentlyCreated) {
                    $imported++;
                } else {
                    // The catalog wins for a key it also carries: this
                    // is the one deliberate overwrite path, surfaced by
                    // a confirmation on the import button.
                    $updated++;
                }
            }
        });

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Map one raw catalog entry to preset attributes, or null when it
     * cannot be used. Every field is bounded to what the columns can
     * hold so a bloated catalog entry becomes a counted skip, never a
     * database error mid-import.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function normalize(array $entry): ?array
    {
        $key = isset($entry['key']) ? trim((string) $entry['key']) : '';
        $label = isset($entry['label']) ? trim((string) $entry['label']) : '';
        $amount = isset($entry['amount']) ? trim((string) $entry['amount']) : '';

        if ($key === '' || $label === '' || $amount === '') {
            return null;
        }

        if (mb_strlen($key) > 255 || mb_strlen($label) > 255) {
            return null;
        }

        $currency = strtoupper(trim((string) ($entry['currency'] ?? 'USD')));

        try {
            $money = Money::ofString($amount, $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($money->isNegative()) {
            return null;
        }

        $url = trim((string) ($entry['url'] ?? ''));
        $period = (string) ($entry['period'] ?? 'monthly');

        if (Period::tryFrom($period) === null) {
            return null;
        }

        $vendor = trim((string) ($entry['vendor'] ?? 'AI'));
        $name = trim((string) ($entry['name'] ?? $label));
        $category = trim((string) ($entry['category'] ?? 'ai'));
        $vendor = $vendor === '' ? 'AI' : $vendor;
        $name = $name === '' ? $label : $name;
        $category = $category === '' ? 'ai' : $category;

        if (mb_strlen($vendor) > 255 || mb_strlen($name) > 255 || mb_strlen($category) > 255 || mb_strlen($url) > 2048) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'vendor' => $vendor,
            'name' => $name,
            'category' => $category,
            'amount_minor' => $money->amountMinor,
            'currency' => $money->currency,
            'period' => $period,
            'auto_renew' => (bool) ($entry['auto_renew'] ?? true),
            'url' => str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : null,
        ];
    }
}
