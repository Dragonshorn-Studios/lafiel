<?php

namespace App\Domain\History;

use App\Domain\Costs\Projection\CostProjector;
use App\Domain\Costs\Projection\ProjectionResult;
use App\Domain\History\Models\CostSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures one daily cost snapshot: the projection for the date, its
 * completeness counters, and the full breakdown. One row per date — a
 * later capture on the same date updates the row only when the stored
 * output would change, so identical runs add no noise. The checksum
 * fingerprints the stored payload itself (totals, completeness,
 * breakdown), which means anything the stored snapshot depends on —
 * amounts, evidence, staleness, provider labels — correctly counts as
 * a change. `fx_used` stays null in v1 (there is no FX source) and is
 * never written after capture: today's rate must not rewrite history.
 * Snapshots are historical output, never a second source of truth.
 */
final class TakeSnapshot
{
    public function __construct(private readonly CostProjector $projector) {}

    /**
     * Capture the date, or return null when capturing failed. Snapshots
     * are never a second source of truth, so a failed capture is logged
     * and reported instead of failing the operation that triggered it —
     * the next trigger or the daily schedule recovers it.
     */
    public function capture(?CarbonImmutable $onDate = null): ?SnapshotCapture
    {
        try {
            return $this->doCapture($onDate ?? CarbonImmutable::now());
        } catch (Throwable $exception) {
            Log::error('Snapshot capture failed.', [
                'snapshot_date' => $onDate?->toDateString(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function doCapture(CarbonImmutable $onDate): SnapshotCapture
    {
        $result = $this->projector->project($onDate);

        $payload = [
            'totals' => $this->totalsPayload($result),
            'completeness' => $this->completenessPayload($result),
            'calculation_version' => $result->calculationVersion,
            'breakdown' => $result->breakdown(),
        ];

        // The checksum fingerprints the stored payload itself, so the
        // dedupe gate means exactly "the stored output would not
        // change" — whatever moved the projection (amounts, evidence,
        // staleness, provider labels) counts as a change.
        $checksum = hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR));

        $existing = CostSnapshot::query()
            ->whereDate('snapshot_date', $onDate->toDateString())
            ->first();

        if ($existing !== null && $existing->input_checksum === $checksum) {
            return new SnapshotCapture($existing, written: false);
        }

        $snapshot = $this->write($onDate, $payload + ['input_checksum' => $checksum], $existing);

        return new SnapshotCapture($snapshot, written: true);
    }

    /**
     * @return array<string, array{monthly_minor: int, annual_minor: int, one_time_minor: int}>
     */
    private function totalsPayload(ProjectionResult $result): array
    {
        $totals = [];

        foreach ($result->currencies() as $total) {
            $totals[$total->currency] = [
                'monthly_minor' => $total->monthlyMinor,
                'annual_minor' => $total->annualMinor,
                'one_time_minor' => $total->oneTimeMinor,
            ];
        }

        return $totals;
    }

    /**
     * @return array{priced: int, unknown: int, estimate: int, stale: int, shared_unallocated: int, one_time: int}
     */
    private function completenessPayload(ProjectionResult $result): array
    {
        return [
            'priced' => $result->pricedCount(),
            'unknown' => $result->unknownCount,
            'estimate' => $result->estimateCount,
            'stale' => $result->staleCount,
            'shared_unallocated' => $result->sharedUnallocatedCount,
            'one_time' => $result->oneTimeCount,
        ];
    }

    /**
     * One row per date. `fx_used` is intentionally absent from both
     * paths: it is null in v1 (no FX source) and a captured rate is
     * never rewritten by a later capture.
     */
    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(CarbonImmutable $onDate, array $payload, ?CostSnapshot $existing): CostSnapshot
    {
        try {
            if ($existing !== null) {
                $existing->fill($payload)->save();

                return $existing;
            }

            return CostSnapshot::query()->create([
                ...$payload,
                'snapshot_date' => $onDate->startOfDay(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent capture for the same date won the unique
            // index; its row stands.
            return CostSnapshot::query()->whereDate('snapshot_date', $onDate->toDateString())->firstOrFail();
        }
    }
}
