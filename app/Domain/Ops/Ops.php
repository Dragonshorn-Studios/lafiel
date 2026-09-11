<?php

namespace App\Domain\Ops;

/**
 * Cache keys and thresholds for pipeline liveness. The scheduler and
 * the queue each write a heartbeat on their own side of the pipeline:
 * the scheduler writes directly from its process, the queue writes
 * from inside an executed job. A heartbeat's age is the liveness
 * signal — missing only counts as dead once the install marker says
 * the pipeline should have been running.
 */
final class Ops
{
    public const SCHEDULER_HEARTBEAT = 'ops:scheduler-heartbeat';

    public const QUEUE_HEARTBEAT = 'ops:queue-heartbeat';

    public const INSTALLED_AT = 'ops:installed-at';

    /** A heartbeat older than this many minutes reads as dead. */
    public const STALE_AFTER_MINUTES = 5;

    /** Fresh installs get a grace window before liveness is enforced. */
    public const INSTALL_GRACE_MINUTES = 10;
}
