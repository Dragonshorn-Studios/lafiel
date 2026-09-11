<?php

use App\Domain\Ops\Jobs\HeartbeatJob;
use Illuminate\Support\Facades\Schedule;

// Pipeline liveness: the scheduler writes its heartbeat directly; the
// queue heartbeat is written from inside an executed job, so a dead
// worker stops the beat. OpsHealth reads both.
Schedule::command('lafiel:heartbeat')->everyMinute();
Schedule::job(new HeartbeatJob)->everyMinute();

// The scheduled OVH cycle: every enabled account syncs once a day.
Schedule::command('lafiel:sync', ['--trigger' => 'schedule'])->daily()->at('04:00');

// Replayable daily snapshots; material changes snapshot immediately
// regardless.
Schedule::command('lafiel:snapshot')->daily()->at('23:40');
