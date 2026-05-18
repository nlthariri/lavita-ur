<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('retention:run --phase=daily')
    ->dailyAt('02:10')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->description('Dagelijkse retentie: e-mail body scrub (30d) + audit IP scrub (90d)');

Schedule::command('retention:run --phase=deep')
    ->monthlyOn(1, '03:30')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->description('Maandelijkse diepe pseudonimisering (7-jaar retentie)');

Schedule::command('reminder:pending-input')
    ->dailyAt('08:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping();

Schedule::command('integrity:email-evidence --fail-on-corruption')
    ->dailyAt('02:40')
    ->withoutOverlapping();

Schedule::command('integrity:evidence-privileges:verify --fail-on-violation')
    ->dailyAt('02:50')
    ->withoutOverlapping();

Schedule::command('integrity:email-evidence:escalations:report --fail-on-open')
    ->dailyAt('03:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping();

Schedule::command('notifications:anniversary')
    ->dailyAt('06:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->description('Dagelijkse versleutelde backup (spatie/laravel-backup)');

Schedule::command('backup:clean')
    ->dailyAt('02:30')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->description('Verwijder backups ouder dan 30 dagen');

Schedule::command('backup:verify')
    ->dailyAt('03:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->description('Dagelijkse backup-integriteitscheck (decrypt + SHA-256 manifest)');
