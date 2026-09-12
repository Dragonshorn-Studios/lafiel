<?php

namespace App\Domain\Ops;

use App\Domain\History\Models\CostSnapshot;
use App\Domain\Providers\Models\ProviderCredential;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pipeline liveness checks for the health endpoint and `lafiel:ops`.
 * Every check is total: it returns an OpsCheck row even when its
 * backend is broken, so the matrix always prints and /up always has a
 * verdict. Critical failures mean the pipeline is broken; the
 * snapshot-age check surfaces degradation without turning the app red.
 */
final readonly class OpsHealth
{
    /**
     * @return list<OpsCheck>
     */
    public function checks(): array
    {
        return [
            $this->guard('database', fn (): OpsCheck => $this->database()),
            $this->guard('scheduler', fn (): OpsCheck => $this->heartbeat(Ops::SCHEDULER_HEARTBEAT, 'scheduler')),
            $this->guard('queue', fn (): OpsCheck => $this->heartbeat(Ops::QUEUE_HEARTBEAT, 'queue')),
            $this->guard('credentials', fn (): OpsCheck => $this->credentials()),
            $this->guard('snapshots', fn (): OpsCheck => $this->snapshots()),
        ];
    }

    /**
     * The critical checks that are currently failing — empty means the
     * pipeline is healthy. Each row carries its name and detail.
     *
     * @return list<OpsCheck>
     */
    public function criticalFailures(): array
    {
        return array_values(array_filter(
            $this->checks(),
            fn (OpsCheck $check): bool => $check->isFailure(),
        ));
    }

    /**
     * A broken backend must yield a failed row, never an exception:
     * checks() is total by construction.
     */
    private function guard(string $name, callable $check): OpsCheck
    {
        try {
            return $check();
        } catch (Throwable $exception) {
            return OpsCheck::fail($name, 'check failed: '.$exception->getMessage());
        }
    }

    private function database(): OpsCheck
    {
        try {
            DB::select('select 1');

            return OpsCheck::pass('database', 'reachable');
        } catch (Throwable $exception) {
            return OpsCheck::fail('database', 'unreachable: '.$exception->getMessage());
        }
    }

    /**
     * A heartbeat that never appeared counts as dead only after the
     * install grace window: the anchor is the first administrator's
     * creation — durable state that survives cache flushes and arms
     * upgraded installs too. A beat older than the staleness threshold
     * means the process is dead.
     */
    private function heartbeat(string $key, string $label): OpsCheck
    {
        $beat = Cache::get($key);

        if ($beat === null) {
            return $this->withinInstallGrace()
                ? OpsCheck::pass($label, 'no heartbeat yet (fresh install)', critical: true)
                : OpsCheck::fail($label, 'no heartbeat ever recorded');
        }

        $ageMinutes = $this->ageMinutes($beat);

        if ($ageMinutes > Ops::STALE_AFTER_MINUTES) {
            return OpsCheck::fail($label, 'last beat '.round($ageMinutes).' minute(s) ago');
        }

        return OpsCheck::pass($label, 'last beat '.round($ageMinutes).' minute(s) ago');
    }

    /**
     * Stored provider credentials must still decrypt with the current
     * APP_KEY: a rotated or missing key makes them unreadable, and
     * that has to be loud rather than a failure on the next sync.
     */
    private function credentials(): OpsCheck
    {
        $credentials = ProviderCredential::query()->get(['id', 'payload']);

        if ($credentials->isEmpty()) {
            return OpsCheck::pass('credentials', 'none stored');
        }

        foreach ($credentials as $credential) {
            try {
                $decrypted = $credential->readablePayload();
            } catch (DecryptException $exception) {
                return OpsCheck::fail('credentials', "credential #{$credential->id} cannot be read: ".$exception->getMessage());
            }

        }

        return OpsCheck::pass('credentials', "{$credentials->count()} readable");
    }

    /**
     * Warning-level: a snapshot lagging more than two days means the
     * daily capture pipeline is not running. Never critical —
     * snapshots are historical output, and a fresh install has none.
     * A future-dated snapshot is clock skew and reads as fresh.
     */
    private function snapshots(): OpsCheck
    {
        $latest = CostSnapshot::query()->max('snapshot_date');

        if ($latest === null) {
            return OpsCheck::pass('snapshots', 'none captured yet', critical: false);
        }

        $ageDays = CarbonImmutable::parse($latest)->startOfDay()->diffInDays(now()->startOfDay());

        if ($ageDays > 2.0) {
            return OpsCheck::fail('snapshots', "latest snapshot is from {$latest}", critical: false);
        }

        return OpsCheck::pass('snapshots', "latest snapshot {$latest}", critical: false);
    }

    private function withinInstallGrace(): bool
    {
        $installedAt = $this->installedAt();

        if ($installedAt === null) {
            return true;
        }

        return $this->ageMinutes($installedAt) <= Ops::INSTALL_GRACE_MINUTES;
    }

    /**
     * The app counts as installed once the first administrator exists —
     * durable state that survives cache flushes and arms upgraded
     * installs too. Null means setup has not happened yet.
     */
    private function installedAt(): ?CarbonImmutable
    {
        $oldest = User::query()->min('created_at');

        return $oldest === null ? null : CarbonImmutable::parse($oldest);
    }

    /**
     * Minutes since the timestamp, clamped at zero (a future timestamp
     * is clock skew and reads as alive, not stale). Float on purpose:
     * truncation would widen every documented threshold by a minute.
     */
    private function ageMinutes(string $timestamp): float
    {
        return max(0, CarbonImmutable::parse($timestamp)->diffInMinutes(now()));
    }
}
