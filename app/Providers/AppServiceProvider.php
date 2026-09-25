<?php

namespace App\Providers;

use App\Domain\Ops\Commands\HeartbeatCommand;
use App\Domain\Ops\Commands\OpsCommand;
use App\Domain\Providers\AdapterRegistry;
use App\Domain\Providers\Cloudflare\CloudflareCredentialSchema;
use App\Domain\Providers\Cloudflare\CloudflareProviderAdapter;
use App\Domain\Providers\Contabo\ContaboCredentialSchema;
use App\Domain\Providers\Contabo\ContaboProviderAdapter;
use App\Domain\Providers\CredentialSchemas;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudCredentialSchema;
use App\Domain\Providers\Hetzner\Cloud\HetznerCloudProviderAdapter;
use App\Domain\Providers\OpenRouter\OpenRouterCredentialSchema;
use App\Domain\Providers\OpenRouter\OpenRouterProviderAdapter;
use App\Domain\Providers\Ovh\OvhCredentialSchema;
use App\Domain\Providers\Ovh\OvhProviderAdapter;
use App\Listeners\EnsureOpsHealthy;
use App\Listeners\VerifyDatabaseHealth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);
        $this->app->singleton(CredentialSchemas::class);
        $this->app->singleton(OvhProviderAdapter::class);
        $this->app->singleton(CloudflareProviderAdapter::class);
        $this->app->singleton(ContaboProviderAdapter::class);
        $this->app->singleton(HetznerCloudProviderAdapter::class);
        $this->app->singleton(OpenRouterProviderAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $adapters = $this->app->make(AdapterRegistry::class);
        $adapters->register('ovh', $this->app->make(OvhProviderAdapter::class));
        $adapters->register('cloudflare', $this->app->make(CloudflareProviderAdapter::class));
        $adapters->register('contabo', $this->app->make(ContaboProviderAdapter::class));
        $adapters->register('hetzner-cloud', $this->app->make(HetznerCloudProviderAdapter::class));
        $adapters->register('openrouter', $this->app->make(OpenRouterProviderAdapter::class));

        $schemas = $this->app->make(CredentialSchemas::class);
        $schemas->register('ovh', OvhCredentialSchema::class);
        $schemas->register('cloudflare', CloudflareCredentialSchema::class);
        $schemas->register('contabo', ContaboCredentialSchema::class);
        $schemas->register('hetzner-cloud', HetznerCloudCredentialSchema::class);
        $schemas->register('openrouter', OpenRouterCredentialSchema::class);

        Event::listen(DiagnosingHealth::class, VerifyDatabaseHealth::class);
        Event::listen(DiagnosingHealth::class, EnsureOpsHealthy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                HeartbeatCommand::class,
                OpsCommand::class,
            ]);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
