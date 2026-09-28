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
 * explicit replacement for the old silent AI_PRESETS_URL merge. Two
 * source formats are supported: the lafiel catalog shape (a keyed
 * object of `{key, label, amount, …}` entries) and the
 * china-ai-arbitrage plans export (a `plans` array of consumer AI
 * subscription plans, data licensed CC BY 4.0 — attribution is kept
 * as `source_url` on every imported row).
 *
 * Entries are upserted by key (the catalog wins for that key);
 * entries that are malformed or carry an unusable amount are skipped
 * and counted, never fatal. Every imported row is tagged
 * `source: catalog`, which hides the built-in transcriptions from the
 * pickers — a live catalog demotes them to a crutch.
 */
final class ImportSubscriptionPresets
{
    /** The china-ai-arbitrage consumer-plan catalog (data: CC BY 4.0). */
    public const CHINA_AI_ARBITRAGE_URL = 'https://www.china-ai-arbitrage.xyz/data/plans.json';

    public const SOURCE_LAFIEL = 'lafiel';

    public const SOURCE_CHINA_AI_ARBITRAGE = 'china-ai-arbitrage';

    /**
     * @return array{imported: int, updated: int, skipped: int}
     *
     * @throws ValidationException when the catalog cannot be fetched or read
     */
    public function import(?string $url = null, string $source = self::SOURCE_LAFIEL): array
    {
        $url = trim($url ?? $this->defaultUrl($source));

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

        if (mb_strlen($url) > 2048) {
            throw ValidationException::withMessages([
                'url' => __('The catalog URL is too long.'),
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

        $entries = $source === self::SOURCE_CHINA_AI_ARBITRAGE
            ? $this->arbitrageEntries($catalog)
            : $this->lafielEntries($catalog);

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        // All-or-nothing: the confirmed overwrite must never leave a
        // half-applied catalog behind on a mid-import failure.
        DB::transaction(function () use (&$imported, &$updated, &$skipped, $entries, $source, $url): void {
            foreach ($entries as $entry) {
                $attributes = match ($source) {
                    self::SOURCE_CHINA_AI_ARBITRAGE => is_array($entry) ? $this->normalizeArbitrage($entry) : null,
                    default => is_array($entry) ? $this->normalize($entry) : null,
                };

                if ($attributes === null) {
                    $skipped++;

                    continue;
                }

                $attributes['source'] = 'catalog';
                $attributes['source_url'] = $url;

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
     * The URL an empty input means for the chosen source.
     */
    private function defaultUrl(string $source): string
    {
        return $source === self::SOURCE_CHINA_AI_ARBITRAGE
            ? self::CHINA_AI_ARBITRAGE_URL
            : (string) config('services.ai_presets_url');
    }

    /**
     * Pull the plan entries out of a china-ai-arbitrage export; a
     * body without the documented `plans` array is a shape mismatch
     * and must be reported as such, not as N skipped entries.
     *
     * @param  array<mixed>  $catalog
     * @return array<mixed>
     */
    private function arbitrageEntries(array $catalog): array
    {
        $plans = $catalog['plans'] ?? null;

        if (! is_array($plans) || $plans === []) {
            throw ValidationException::withMessages([
                'url' => __('The catalog does not look like a china-ai-arbitrage plans export (no "plans" array).'),
            ]);
        }

        return $plans;
    }

    /**
     * A lafiel-shape catalog is a keyed object of plan entries; a body
     * carrying none of them (wrong URL, foreign format) is a shape
     * mismatch and must be reported as such, not as N skipped entries.
     *
     * @param  array<mixed>  $catalog
     * @return array<mixed>
     */
    private function lafielEntries(array $catalog): array
    {
        foreach ($catalog as $entry) {
            if (is_array($entry) && isset($entry['key']) && trim((string) $entry['key']) !== '') {
                return $catalog;
            }
        }

        throw ValidationException::withMessages([
            'url' => __('The catalog does not look like a lafiel plan catalog (no keyed plan entries).'),
        ]);
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

    /**
     * Map one china-ai-arbitrage plan entry to preset attributes, or
     * null when it cannot be used (free tiers, "—" prices, missing
     * fields, unparseable amounts). Names are read from the `en`
     * locale objects; the period is honestly defaulted to monthly —
     * their `billing` field is localized prose, not data.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function normalizeArbitrage(array $entry): ?array
    {
        $platform = $entry['platform']['en'] ?? null;
        $plan = $entry['plan']['en'] ?? null;
        $providerSlug = $entry['provider_slug'] ?? null;
        $planSlug = $entry['plan_slug'] ?? null;

        if (! is_string($platform) || $platform === ''
            || ! is_string($plan) || $plan === ''
            || ! is_string($providerSlug) || $providerSlug === ''
            || ! is_string($planSlug) || $planSlug === '') {
            return null;
        }

        $priceString = trim((string) ($entry['price'] ?? ''));
        $money = $this->parseDisplayPrice($priceString);

        if ($money === null) {
            return null;
        }

        $key = $providerSlug.':'.$planSlug;

        if (mb_strlen($key) > 255) {
            return null;
        }

        $url = trim((string) ($entry['platform_url'] ?? ''));
        $label = sprintf('%s — %s (%s)', $platform, $plan, $priceString);

        return [
            'key' => $key,
            'label' => mb_substr($label, 0, 255),
            'vendor' => mb_substr($platform, 0, 255),
            'name' => mb_substr($plan, 0, 255),
            'category' => 'ai',
            'amount_minor' => $money->amountMinor,
            'currency' => $money->currency,
            'period' => $this->arbitragePeriod($priceString),
            'auto_renew' => true,
            'url' => str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? mb_substr($url, 0, 2048) : null,
        ];
    }

    /**
     * The billing period a display price's suffix declares: "$200/年"
     * or "$2399.88/yr" is annual; everything else defaults to
     * monthly. Their `billing` field is localized prose, not data.
     */
    private function arbitragePeriod(string $priceString): string
    {
        return preg_match('/\/\s*(yr|year|年)/iu', $priceString) === 1
            ? 'annual'
            : 'monthly';
    }

    /**
     * Parse a display price like "$20", "¥118.00", or "$200/年" into
     * Money. The currency marker must lead; trailing suffixes (per
     * period, locale notes) are ignored on purpose, but digits,
     * commas, or further decimals right after the number mean the
     * amount itself was unreadable ("$1,299", "$20.999") — a counted
     * skip, never a silently wrong price. Zero amounts are not
     * prices — free tiers become counted skips.
     *
     * ¥ (and ￥) are mapped to CNY: this source is China-focused, and
     * the symbol is ambiguous with JPY anywhere else.
     */
    private function parseDisplayPrice(string $raw): ?Money
    {
        if (preg_match('/^(US\$|\$|¥|￥|€|£|USD|CNY|EUR|GBP)\s*([0-9]+(?:\.[0-9]{1,2})?)(?![0-9,.])/u', $raw, $matches) !== 1) {
            return null;
        }

        // Symbol markers map to ISO codes; the bare code markers
        // already are ISO codes and pass through. The alternation and
        // this mapping must stay in sync.
        $currency = match (true) {
            in_array($matches[1], ['US$', '$'], true) => 'USD',
            $matches[1] === '¥', $matches[1] === '￥' => 'CNY',
            $matches[1] === '€' => 'EUR',
            $matches[1] === '£' => 'GBP',
            default => $matches[1],
        };

        try {
            $money = Money::ofString($matches[2], $currency);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $money->isZero() || $money->isNegative() ? null : $money;
    }
}
