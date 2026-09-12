<?php

namespace App\Providers;

use App\Domain\Ops\Commands\HeartbeatCommand;
use App\Domain\Ops\Commands\OpsCommand;
use App\Domain\Providers\AdapterRegistry;
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
        $this->app->singleton(OvhProviderAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $this->app->make(AdapterRegistry::class)
            ->register('ovh', $this->app->make(OvhProviderAdapter::class));

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
