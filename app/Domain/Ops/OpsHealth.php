<?php

namespace App\Domain\Ops;

use App\Domain\History\Models\CostSnapshot;
use App\Domain\Providers\Models\ProviderCredential;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline liveness checks for the health endpoint and `lafiel:ops`.
 * Every check returns a row with `critical` deciding whether it fails
 * the endpoint and the command's exit code; non-critical rows surface
 * degradation (snapshot age) without turning the app red.
 */
final readonly class OpsCheck
{
    public function __construct(
        public string $check,
        public bool $ok,
        public bool $critical,
        public string $detail,
    ) {}
}

final class OpsHealth
{
    /**
     * @return list<OpsCheck>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->heartbeat(Ops::SCHEDULER_HEARTBEAT, 'scheduler'),
            $this->heartbeat(Ops::QUEUE_HEARTBEAT, 'queue'),
            $this->credentials(),
            $this->snapshots(),
        ];
    }

    /**
     * True when any critical check fails — the "the app is broken" set.
     */
    public function criticalFailures(): array
    {
        return array_values(array_filter(
            $this->checks(),
            fn (OpsCheck $check): bool => $check->critical && ! $check->ok,
        ));
    }

    private function database(): OpsCheck
    {
        try {
            DB::select('select 1');

            return new OpsCheck('database', true, true, 'reachable');
        } catch (\Throwable $exception) {
            return new OpsCheck('database', false, true, $exception->getMessage());
        }
    }

    /**
     * A heartbeat that never appeared counts as dead only after the
     * install grace window: a fresh install has no pipeline to lose.
     */
    private function heartbeat(string $key, string $label): OpsCheck
    {
        $beat = Cache::get($key);

        if ($beat === null) {
            $graced = $this->withinInstallGrace();

            return new OpsCheck(
                $label,
                $graced,
                true,
                $graced ? 'no heartbeat yet (fresh install)' : 'no heartbeat ever recorded',
            );
        }

        $age = $this->ageMinutes($beat);

        return new OpsCheck(
            $label,
            $age <= Ops::STALE_AFTER_MINUTES,
            true,
            "last beat {$age} minute(s) ago",
        );
    }

    /**
     * Stored provider credentials must still decrypt with the current
     * APP_KEY: a rotated key makes them unreadable, and that has to be
     * loud rather than a failure on the next sync.
     */
    private function credentials(): OpsCheck
    {
        $credentials = ProviderCredential::query()->get(['id', 'payload']);

        if ($credentials->isEmpty()) {
            return new OpsCheck('credentials', true, true, 'none stored');
        }

        foreach ($credentials as $credential) {
            try {
                $credential->payload;
            } catch (DecryptException) {
                return new OpsCheck('credentials', false, true, "credential #{$credential->id} is unreadable with the current APP_KEY");
            }
        }

        return new OpsCheck('credentials', true, true, "{$credentials->count()} readable");
    }

    /**
     * Warning-level: a snapshot lagging more than two days (or missing
     * while costs exist) means the daily capture pipeline is not
     * running. Never critical — snapshots are historical output.
     */
    private function snapshots(): OpsCheck
    {
        $latest = CostSnapshot::query()->max('snapshot_date');

        if ($latest === null) {
            return new OpsCheck('snapshots', true, false, 'none captured yet');
        }

        $ageDays = CarbonImmutable::parse($latest)->startOfDay()->diffInDays(now()->startOfDay());

        if (abs((int) $ageDays) > 2) {
            return new OpsCheck('snapshots', false, false, "latest snapshot is from {$latest}");
        }

        return new OpsCheck('snapshots', true, false, "latest snapshot {$latest}");
    }

    private function withinInstallGrace(): bool
    {
        $installedAt = Cache::get(Ops::INSTALLED_AT);

        if ($installedAt === null) {
            return true;
        }

        return $this->ageMinutes($installedAt) <= Ops::INSTALL_GRACE_MINUTES;
    }

    private function ageMinutes(string $timestamp): int
    {
        return (int) abs(CarbonImmutable::parse($timestamp)->diffInMinutes(now()));
    }
}
