<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('retention:run')
    ->dailyAt('02:10')
    ->withoutOverlapping();

Schedule::command('reminder:pending-input --days=1')
    ->dailyAt('02:20')
    ->withoutOverlapping();

Schedule::command('integrity:email-evidence --fail-on-corruption')
    ->dailyAt('02:40')
    ->withoutOverlapping();

Schedule::command('integrity:evidence-privileges:verify --fail-on-violation')
    ->dailyAt('02:50')
    ->withoutOverlapping();

Schedule::command('integrity:email-evidence:escalations:report --fail-on-open')
    ->dailyAt('03:00')
    ->withoutOverlapping();
