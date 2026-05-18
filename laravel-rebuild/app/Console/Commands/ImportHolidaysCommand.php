<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Services\HolidaysService;
use Illuminate\Console\Command;

/**
 * Artisan command: importeer Nederlandse feestdagen voor een opgegeven jaar.
 *
 * Gebruikt `HolidaysService::computeNlHolidaysForYear` om de feestdagen
 * te berekenen en slaat ze op via upsert op de uniek-index `(year, date)`.
 *
 * Requirements: 7.5
 */
class ImportHolidaysCommand extends Command
{
    protected $signature = 'holidays:import {year}';

    protected $description = 'Importeer Nederlandse nationale feestdagen voor het opgegeven jaar.';

    public function handle(HolidaysService $service): int
    {
        $year = (int) $this->argument('year');

        if ($year < 1900 || $year > 2099) {
            $this->error("Jaar moet tussen 1900 en 2099 liggen. Gegeven: {$year}");

            return self::FAILURE;
        }

        $holidays = $service->computeNlHolidaysForYear($year);

        $rows = array_map(fn (array $h) => [
            'year' => $year,
            'date' => $h['date'],
            'name' => $h['name'],
            'is_national' => $h['is_national'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $holidays);

        Holiday::upsert($rows, ['year', 'date'], ['name', 'is_national', 'updated_at']);

        $count = count($holidays);
        $this->info("✓ {$count} feestdagen geïmporteerd voor {$year}.");

        return self::SUCCESS;
    }
}
