<?php

use Illuminate\Support\Facades\Schedule;

// Purga diaria de tokens Sanctum vencidos hace más de 24 h (la expiración la fija sanctum.expiration).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
