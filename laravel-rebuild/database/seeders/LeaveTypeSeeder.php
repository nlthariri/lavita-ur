<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * LeaveTypeSeeder
 *
 * Seedt de 4 standaard verlof-types per organisatie (Requirement 11.2).
 *
 * Standaard types:
 *  - VAKANTIE      (counts_towards_balance = true)
 *  - BIJZONDER     (counts_towards_balance = false)
 *  - ONBETAALD    (counts_towards_balance = false)
 *  - OUDERSCHAP   (counts_towards_balance = false)
 *
 * Idempotentie: gebruikt `firstOrCreate` op `(organization_id, code)`
 * zodat herhaald `php artisan db:seed` geen duplicaten produceert.
 * Bestaande records worden NIET overschreven (bewust: admins kunnen
 * namen/instellingen hebben aangepast).
 */
class LeaveTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * De standaard verlof-types die per organisatie worden aangemaakt.
     *
     * @var array<int, array{code: string, name: string, counts_towards_balance: bool, max_days_per_year: int|null}>
     */
    private const DEFAULT_LEAVE_TYPES = [
        [
            'code' => 'VAKANTIE',
            'name' => 'Vakantieverlof',
            'counts_towards_balance' => true,
            'max_days_per_year' => null,
        ],
        [
            'code' => 'BIJZONDER',
            'name' => 'Bijzonder verlof',
            'counts_towards_balance' => false,
            'max_days_per_year' => null,
        ],
        [
            'code' => 'ONBETAALD',
            'name' => 'Onbetaald verlof',
            'counts_towards_balance' => false,
            'max_days_per_year' => null,
        ],
        [
            'code' => 'OUDERSCHAP',
            'name' => 'Ouderschapsverlof',
            'counts_towards_balance' => false,
            'max_days_per_year' => null,
        ],
    ];

    /**
     * Seed de standaard verlof-types voor iedere organisatie.
     */
    public function run(): void
    {
        Organization::query()->each(function (Organization $organization): void {
            foreach (self::DEFAULT_LEAVE_TYPES as $type) {
                LeaveType::firstOrCreate(
                    [
                        'organization_id' => (int) $organization->id,
                        'code' => $type['code'],
                    ],
                    [
                        'name' => $type['name'],
                        'counts_towards_balance' => $type['counts_towards_balance'],
                        'max_days_per_year' => $type['max_days_per_year'],
                        'is_active' => true,
                    ],
                );
            }
        });
    }
}
