<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;

/**
 * Service voor het beheer en berekenen van Nederlandse nationale feestdagen.
 *
 * Gebruikt het Gauss-algoritme voor Paasberekening en leidt alle
 * bewegelijke feestdagen (Goede Vrijdag, Pasen, Hemelvaart, Pinksteren)
 * daaruit af. Vaste feestdagen (Nieuwjaar, Koningsdag, Bevrijdingsdag,
 * Kerst) worden volgens de officiële regels berekend.
 *
 * Requirements: 7.4, 7.5, 7.6
 */
class HolidaysService
{
    /**
     * Bereken alle Nederlandse nationale feestdagen voor een gegeven jaar.
     *
     * Retourneert een array van feestdagen met date (Y-m-d), name en is_national.
     *
     * Feestdagen:
     *  1. Nieuwjaarsdag (01-01)
     *  2. Goede Vrijdag (Pasen - 2)
     *  3. Eerste Paasdag (Gauss-Pasen)
     *  4. Tweede Paasdag (Pasen + 1)
     *  5. Koningsdag (27-04, of 26-04 als 27-04 een zondag is)
     *  6. Bevrijdingsdag (05-05, alleen als jaar mod 5 == 0)
     *  7. Hemelvaartsdag (Pasen + 39)
     *  8. Eerste Pinksterdag (Pasen + 49)
     *  9. Tweede Pinksterdag (Pasen + 50)
     * 10. Eerste Kerstdag (25-12)
     * 11. Tweede Kerstdag (26-12)
     *
     * @return array<int, array{date: string, name: string, is_national: bool}>
     */
    public function computeNlHolidaysForYear(int $year): array
    {
        $easter = $this->computeEasterDate($year);
        $holidays = [];

        // 1. Nieuwjaarsdag
        $holidays[] = [
            'date' => sprintf('%04d-01-01', $year),
            'name' => 'Nieuwjaarsdag',
            'is_national' => true,
        ];

        // 2. Goede Vrijdag (Pasen - 2)
        $holidays[] = [
            'date' => $easter->copy()->subDays(2)->format('Y-m-d'),
            'name' => 'Goede Vrijdag',
            'is_national' => true,
        ];

        // 3. Eerste Paasdag
        $holidays[] = [
            'date' => $easter->format('Y-m-d'),
            'name' => 'Eerste Paasdag',
            'is_national' => true,
        ];

        // 4. Tweede Paasdag (Pasen + 1)
        $holidays[] = [
            'date' => $easter->copy()->addDays(1)->format('Y-m-d'),
            'name' => 'Tweede Paasdag',
            'is_national' => true,
        ];

        // 5. Koningsdag (27 april, of 26 april als 27 april een zondag is)
        $koningsdag = Carbon::createFromDate($year, 4, 27);
        if ($koningsdag->isSunday()) {
            $koningsdag = Carbon::createFromDate($year, 4, 26);
        }
        $holidays[] = [
            'date' => $koningsdag->format('Y-m-d'),
            'name' => 'Koningsdag',
            'is_national' => true,
        ];

        // 6. Bevrijdingsdag (5 mei, alleen in lustrumjaren: jaar mod 5 == 0)
        if ($year % 5 === 0) {
            $holidays[] = [
                'date' => sprintf('%04d-05-05', $year),
                'name' => 'Bevrijdingsdag',
                'is_national' => true,
            ];
        }

        // 7. Hemelvaartsdag (Pasen + 39)
        $holidays[] = [
            'date' => $easter->copy()->addDays(39)->format('Y-m-d'),
            'name' => 'Hemelvaartsdag',
            'is_national' => true,
        ];

        // 8. Eerste Pinksterdag (Pasen + 49)
        $holidays[] = [
            'date' => $easter->copy()->addDays(49)->format('Y-m-d'),
            'name' => 'Eerste Pinksterdag',
            'is_national' => true,
        ];

        // 9. Tweede Pinksterdag (Pasen + 50)
        $holidays[] = [
            'date' => $easter->copy()->addDays(50)->format('Y-m-d'),
            'name' => 'Tweede Pinksterdag',
            'is_national' => true,
        ];

        // 10. Eerste Kerstdag
        $holidays[] = [
            'date' => sprintf('%04d-12-25', $year),
            'name' => 'Eerste Kerstdag',
            'is_national' => true,
        ];

        // 11. Tweede Kerstdag
        $holidays[] = [
            'date' => sprintf('%04d-12-26', $year),
            'name' => 'Tweede Kerstdag',
            'is_national' => true,
        ];

        return $holidays;
    }

    /**
     * Haal feestdagen op uit de database voor een bepaald jaar.
     *
     * @return array<int, array{date: string, name: string, is_national: bool}>
     */
    public function forYear(int $year): array
    {
        return Holiday::forYear($year)
            ->orderBy('date')
            ->get()
            ->map(fn (Holiday $h) => [
                'date' => $h->date->format('Y-m-d'),
                'name' => $h->name,
                'is_national' => (bool) $h->is_national,
            ])
            ->all();
    }

    /**
     * Bereken de Paasdatum voor een gegeven jaar via het Gauss-algoritme
     * (Anonymous Gregorian algorithm / Meeus/Jones/Butcher).
     */
    private function computeEasterDate(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::createFromDate($year, $month, $day)->startOfDay();
    }
}
