<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Voegt een CHECK constraint toe op work_entries.type om alleen
 * geldige entry-types toe te staan op database-niveau.
 *
 * Geldige types: WORK, SICK, LEAVE, HOLIDAY, OTHER
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite ondersteunt geen ALTER TABLE ADD CONSTRAINT,
            // maar de applicatie-laag valideert al. Skip in tests.
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE work_entries ADD CONSTRAINT chk_work_entry_type CHECK (type IN ('WORK', 'SICK', 'LEAVE', 'HOLIDAY', 'OTHER'))");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE work_entries ADD CONSTRAINT chk_work_entry_type CHECK (type IN ('WORK', 'SICK', 'LEAVE', 'HOLIDAY', 'OTHER'))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE work_entries DROP CHECK chk_work_entry_type');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE work_entries DROP CONSTRAINT chk_work_entry_type');
        }
    }
};
