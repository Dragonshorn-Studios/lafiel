<?php

namespace App\Domain\History;

use App\Domain\History\Models\CostSnapshot;

/**
 * The outcome of one capture attempt: the snapshot that stands for the
 * date, and whether this capture actually wrote it. A deduped capture
 * returns the existing row with `written = false`.
 */
final readonly class SnapshotCapture
{
    public function __construct(
        public CostSnapshot $snapshot,
        public bool $written,
    ) {}
}
