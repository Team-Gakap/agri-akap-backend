<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full climate sync once daily (daily + hourly + 30-day historical archive).
Schedule::command('weather:sync-all')
    ->dailyAt('05:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// Keep the 6-Hour Action Window fresh throughout the day.
Schedule::command('weather:fetch --hourly')
    ->everyFourHours()
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

// Evening refresh of the 7-day daily forecast (no historical — that runs at 05:00).
Schedule::command('weather:fetch --daily')
    ->dailyAt('17:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();
