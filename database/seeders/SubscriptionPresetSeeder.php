<?php

namespace Database\Seeders;

use App\Domain\Costs\Models\SubscriptionPreset;
use App\Domain\Support\ValueObjects\Money;
use Illuminate\Database\Seeder;

/**
 * Seeds the subscription library with the built-in plan catalog:
 * AI plans (formerly the hardcoded AiSubscriptionPresets list,
 * aligned with AIPricing.guru data) and provider VPS tiers curated
 * manually. Idempotent: re-seeding only fills in missing keys and
 * never overwrites an existing preset — user edits survive. The one
 * deliberate overwrite path is the confirmed "Import from catalog"
 * action, where the catalog wins for keys it carries.
 */
class SubscriptionPresetSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (self::presets() as $preset) {
            SubscriptionPreset::firstOrCreate(
                ['key' => $preset['key']],
                $preset,
            );
        }
    }

    /**
     * The built-in catalog: AI plans aligned with AIPricing.guru
     * data, plus provider VPS tiers curated manually.
     *
     * @return list<array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            self::preset('openai:chatgpt_plus', 'OpenAI', 'ChatGPT Plus', '20.00', 'https://chatgpt.com'),
            self::preset('openai:chatgpt_team', 'OpenAI', 'ChatGPT Team', '25.00', 'https://chatgpt.com', 'monthly', 'seat'),
            self::preset('openai:chatgpt_pro', 'OpenAI', 'ChatGPT Pro', '200.00', 'https://chatgpt.com'),
            self::preset('anthropic:claude_pro', 'Anthropic', 'Claude Pro', '20.00', 'https://claude.ai'),
            self::preset('anthropic:claude_team', 'Anthropic', 'Claude Team', '25.00', 'https://claude.ai', 'monthly', 'seat'),
            self::preset('github:copilot_individual', 'GitHub', 'Copilot Individual', '10.00', 'https://github.com/features/copilot'),
            self::preset('github:copilot_individual_annual', 'GitHub', 'Copilot Individual (Annual)', '100.00', 'https://github.com/features/copilot', 'annual'),
            self::preset('github:copilot_business', 'GitHub', 'Copilot Business', '19.00', 'https://github.com/features/copilot', 'monthly', 'seat'),
            self::preset('cursor:pro', 'Cursor', 'Cursor Pro', '20.00', 'https://www.cursor.com'),
            self::preset('cursor:business', 'Cursor', 'Cursor Business', '40.00', 'https://www.cursor.com'),
            self::preset('perplexity:pro', 'Perplexity', 'Perplexity Pro', '20.00', 'https://www.perplexity.ai'),
            self::preset('xai:grok_premium', 'xAI', 'Grok Premium', '8.00', 'https://x.ai'),
            self::preset('midjourney:basic', 'Midjourney', 'Midjourney Basic', '10.00', 'https://www.midjourney.com'),
            self::preset('midjourney:standard', 'Midjourney', 'Midjourney Standard', '30.00', 'https://www.midjourney.com'),
            self::preset('google:gemini_advanced', 'Google', 'Gemini Advanced', '19.99', 'https://gemini.google.com'),
            self::preset('elevenlabs:starter', 'ElevenLabs', 'ElevenLabs Starter', '5.00', 'https://elevenlabs.io'),
            self::preset('elevenlabs:creator', 'ElevenLabs', 'ElevenLabs Creator', '22.00', 'https://elevenlabs.io'),
            self::preset('poe:subscription', 'Poe', 'Poe Subscription', '19.99', 'https://poe.com'),

            // RackNerd's recurring promotional VPS tiers. RackNerd has
            // no billing API (docs/integrations.md) — these are
            // editable starting points whose prices are promotional
            // and variable, never authoritative.
            self::preset('racknerd:kvm_768mb_annual', 'RackNerd', 'KVM 768MB', '10.99', 'https://www.racknerd.com', 'annual', category: 'compute'),
            self::preset('racknerd:kvm_1gb_annual', 'RackNerd', 'KVM 1GB', '11.99', 'https://www.racknerd.com', 'annual', category: 'compute'),
            self::preset('racknerd:kvm_2gb_annual', 'RackNerd', 'KVM 2GB', '19.99', 'https://www.racknerd.com', 'annual', category: 'compute'),
            self::preset('racknerd:kvm_3gb_annual', 'RackNerd', 'KVM 3GB', '29.99', 'https://www.racknerd.com', 'annual', category: 'compute'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preset(string $key, string $vendor, string $name, string $amount, string $url, string $period = 'monthly', string $unit = '', string $category = 'ai'): array
    {
        $money = Money::ofString($amount, 'USD');
        $per = $period === 'annual' ? 'yr' : 'mo';
        $unitSuffix = $unit === '' ? '' : '/'.$unit;

        return [
            'key' => $key,
            'label' => sprintf('%s — %s ($%s/%s%s)', $vendor, $name, $amount, $per, $unitSuffix),
            'vendor' => $vendor,
            'name' => $name,
            'category' => $category,
            'amount_minor' => $money->amountMinor,
            'currency' => $money->currency,
            'period' => $period,
            'auto_renew' => true,
            'url' => $url,
            'source' => 'builtin',
        ];
    }
}
