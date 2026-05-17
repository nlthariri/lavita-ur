<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest test bootstrap — LaVita Urenregistratie
|--------------------------------------------------------------------------
|
| Aangemaakt voor task 1.2 van spec `lavita-urenregistratie`. Hier mappen
| we Pest's `uses()` op de juiste base TestCase + traits, registreren we
| een korte alias voor de `RefreshDatabase`-trait, en stellen we de
| standaard property-test-iteratie-waarde in (zie design.md, Testing
| Strategy → Property-Based Testing-bibliotheek).
|
| Voor Property-Based Testing gebruiken we `giorgiosironi/eris`, omdat
| `pestphp/pest-plugin-properties` (genoemd in tasks.md 1.2) niet als
| publiek package bestaat; design.md noemt eris-php expliciet als
| documenteerde fallback.
*/

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RefreshesDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test-case binding
|--------------------------------------------------------------------------
|
| Alle Pest-tests onder tests/Feature en tests/Unit erven van Tests\TestCase.
| Feature-tests krijgen daarnaast automatisch de RefreshDatabase-trait,
| zodat een schone in-memory SQLite per test wordt opgezet (conform
| phpunit.xml: DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
*/

uses(TestCase::class)->in('Feature', 'Unit');

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| RefreshDatabase-trait alias
|--------------------------------------------------------------------------
|
| Tasks.md 1.2 vraagt om een korte alias voor de RefreshDatabase-trait,
| zodat property-tests en Eris-generators kort `RefreshesDatabase` kunnen
| importeren in plaats van het volledige Illuminate-pad.
*/

if (! class_exists(RefreshesDatabase::class, false)) {
    class_alias(RefreshDatabase::class, RefreshesDatabase::class);
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Standaard aantal Eris-iteraties per property-test. ATW-properties
| overschrijven dit naar 200 via `->withMaxSize(200)` waar nodig.
*/

if (! function_exists('pbt_default_iterations')) {
    function pbt_default_iterations(): int
    {
        return 100;
    }
}
