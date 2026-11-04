<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purga diaria de tokens Sanctum vencidos hace más de 24 h (la expiración la fija sanctum.expiration).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
