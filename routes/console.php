<?php

use Illuminate\Support\Facades\Schedule;

// Replayable daily snapshots (issue #13); a running scheduler picks
// this up, and material changes snapshot immediately regardless.
Schedule::command('lafiel:snapshot')->daily()->at('23:40');
