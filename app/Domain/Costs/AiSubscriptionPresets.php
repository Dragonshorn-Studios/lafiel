<?php

namespace App\Domain\Costs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AiSubscriptionPresets
{
    private const CACHE_KEY = 'ai_subscription_presets_v1';

    private const CACHE_TTL = 86400; // 24 hours

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $default = self::defaultPresets();

        $url = config('services.ai_presets_url');

        if (! is_string($url) || trim($url) === '') {
            return $default;
        }

        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($default, $url) {
                try {
                    $response = Http::timeout(5)->acceptJson()->get($url);

                    if ($response->successful()) {
                        $remote = $response->json();

                        if (is_array($remote) && $remote !== []) {
                            return self::mergePresets($default, $remote);
                        }
                    }
                } catch (Throwable) {
                    // Fallback to built-in presets on connection/HTTP errors
                }

                return $default;
            });
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Built-in fallback catalog of subscription plans aligned with AIPricing.guru data.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultPresets(): array
    {
        return [
            'openai:chatgpt_plus' => [
                'key' => 'openai:chatgpt_plus',
                'label' => 'OpenAI — ChatGPT Plus ($20/mo)',
                'vendor' => 'OpenAI',
                'name' => 'ChatGPT Plus',
                'category' => 'ai',
                'amount' => '20.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://chatgpt.com',
            ],
            'openai:chatgpt_team' => [
                'key' => 'openai:chatgpt_team',
                'label' => 'OpenAI — ChatGPT Team ($25/mo/seat)',
                'vendor' => 'OpenAI',
                'name' => 'ChatGPT Team',
                'category' => 'ai',
                'amount' => '25.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://chatgpt.com',
            ],
            'openai:chatgpt_pro' => [
                'key' => 'openai:chatgpt_pro',
                'label' => 'OpenAI — ChatGPT Pro ($200/mo)',
                'vendor' => 'OpenAI',
                'name' => 'ChatGPT Pro',
                'category' => 'ai',
                'amount' => '200.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://chatgpt.com',
            ],
            'anthropic:claude_pro' => [
                'key' => 'anthropic:claude_pro',
                'label' => 'Anthropic — Claude Pro ($20/mo)',
                'vendor' => 'Anthropic',
                'name' => 'Claude Pro',
                'category' => 'ai',
                'amount' => '20.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://claude.ai',
            ],
            'anthropic:claude_team' => [
                'key' => 'anthropic:claude_team',
                'label' => 'Anthropic — Claude Team ($25/mo/seat)',
                'vendor' => 'Anthropic',
                'name' => 'Claude Team',
                'category' => 'ai',
                'amount' => '25.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://claude.ai',
            ],
            'github:copilot_individual' => [
                'key' => 'github:copilot_individual',
                'label' => 'GitHub — Copilot Individual ($10/mo)',
                'vendor' => 'GitHub',
                'name' => 'Copilot Individual',
                'category' => 'ai',
                'amount' => '10.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://github.com/features/copilot',
            ],
            'github:copilot_individual_annual' => [
                'key' => 'github:copilot_individual_annual',
                'label' => 'GitHub — Copilot Individual ($100/yr)',
                'vendor' => 'GitHub',
                'name' => 'Copilot Individual (Annual)',
                'category' => 'ai',
                'amount' => '100.00',
                'currency' => 'USD',
                'period' => 'annual',
                'auto_renew' => true,
                'url' => 'https://github.com/features/copilot',
            ],
            'github:copilot_business' => [
                'key' => 'github:copilot_business',
                'label' => 'GitHub — Copilot Business ($19/mo/seat)',
                'vendor' => 'GitHub',
                'name' => 'Copilot Business',
                'category' => 'ai',
                'amount' => '19.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://github.com/features/copilot',
            ],
            'cursor:pro' => [
                'key' => 'cursor:pro',
                'label' => 'Cursor — Pro ($20/mo)',
                'vendor' => 'Cursor',
                'name' => 'Cursor Pro',
                'category' => 'ai',
                'amount' => '20.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://www.cursor.com',
            ],
            'cursor:business' => [
                'key' => 'cursor:business',
                'label' => 'Cursor — Business ($40/mo/seat)',
                'vendor' => 'Cursor',
                'name' => 'Cursor Business',
                'category' => 'ai',
                'amount' => '40.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://www.cursor.com',
            ],
            'perplexity:pro' => [
                'key' => 'perplexity:pro',
                'label' => 'Perplexity — Pro ($20/mo)',
                'vendor' => 'Perplexity',
                'name' => 'Perplexity Pro',
                'category' => 'ai',
                'amount' => '20.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://www.perplexity.ai',
            ],
            'xai:grok_premium' => [
                'key' => 'xai:grok_premium',
                'label' => 'xAI — Grok Premium ($8/mo)',
                'vendor' => 'xAI',
                'name' => 'Grok Premium',
                'category' => 'ai',
                'amount' => '8.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://x.ai',
            ],
            'midjourney:basic' => [
                'key' => 'midjourney:basic',
                'label' => 'Midjourney — Basic Plan ($10/mo)',
                'vendor' => 'Midjourney',
                'name' => 'Midjourney Basic',
                'category' => 'ai',
                'amount' => '10.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://www.midjourney.com',
            ],
            'midjourney:standard' => [
                'key' => 'midjourney:standard',
                'label' => 'Midjourney — Standard Plan ($30/mo)',
                'vendor' => 'Midjourney',
                'name' => 'Midjourney Standard',
                'category' => 'ai',
                'amount' => '30.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://www.midjourney.com',
            ],
            'google:gemini_advanced' => [
                'key' => 'google:gemini_advanced',
                'label' => 'Google — Gemini Advanced ($19.99/mo)',
                'vendor' => 'Google',
                'name' => 'Gemini Advanced',
                'category' => 'ai',
                'amount' => '19.99',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://gemini.google.com',
            ],
            'elevenlabs:starter' => [
                'key' => 'elevenlabs:starter',
                'label' => 'ElevenLabs — Starter ($5/mo)',
                'vendor' => 'ElevenLabs',
                'name' => 'ElevenLabs Starter',
                'category' => 'ai',
                'amount' => '5.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://elevenlabs.io',
            ],
            'elevenlabs:creator' => [
                'key' => 'elevenlabs:creator',
                'label' => 'ElevenLabs — Creator ($22/mo)',
                'vendor' => 'ElevenLabs',
                'name' => 'ElevenLabs Creator',
                'category' => 'ai',
                'amount' => '22.00',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://elevenlabs.io',
            ],
            'poe:subscription' => [
                'key' => 'poe:subscription',
                'label' => 'Poe — Subscription ($19.99/mo)',
                'vendor' => 'Poe',
                'name' => 'Poe Subscription',
                'category' => 'ai',
                'amount' => '19.99',
                'currency' => 'USD',
                'period' => 'monthly',
                'auto_renew' => true,
                'url' => 'https://poe.com',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $preset = self::all()[$key] ?? null;

        return is_array($preset) ? $preset : null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $key => $preset) {
            if (isset($preset['label']) && is_string($preset['label'])) {
                $options[$key] = $preset['label'];
            }
        }

        return $options;
    }

    /**
     * Merge remote presets array into built-in defaults.
     *
     * @param  array<string, array<string, mixed>>  $default
     * @param  array<string, mixed>  $remote
     * @return array<string, array<string, mixed>>
     */
    private static function mergePresets(array $default, array $remote): array
    {
        foreach ($remote as $key => $preset) {
            if (! is_array($preset) || ! isset($preset['key'], $preset['label'], $preset['amount'])) {
                continue;
            }

            $default[(string) $key] = [
                'key' => (string) $preset['key'],
                'label' => (string) $preset['label'],
                'vendor' => (string) ($preset['vendor'] ?? 'AI'),
                'name' => (string) ($preset['name'] ?? $preset['label']),
                'category' => (string) ($preset['category'] ?? 'ai'),
                'amount' => (string) $preset['amount'],
                'currency' => (string) ($preset['currency'] ?? 'USD'),
                'period' => (string) ($preset['period'] ?? 'monthly'),
                'auto_renew' => (bool) ($preset['auto_renew'] ?? true),
                'url' => (string) ($preset['url'] ?? ''),
            ];
        }

        return $default;
    }

    /**
     * Clear cached presets (e.g. for testing or forced refresh).
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
