<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_email_outbox_events_no_update
BEFORE UPDATE ON email_outbox_events
BEGIN
    SELECT RAISE(ABORT, 'email_outbox_events is append-only (update blocked)');
END;
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_email_outbox_events_no_delete
BEFORE DELETE ON email_outbox_events
BEGIN
    SELECT RAISE(ABORT, 'email_outbox_events is append-only (delete blocked)');
END;
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_monthly_report_runs_no_update
BEFORE UPDATE ON monthly_report_runs
BEGIN
    SELECT RAISE(ABORT, 'monthly_report_runs is append-only (update blocked)');
END;
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_monthly_report_runs_no_delete
BEFORE DELETE ON monthly_report_runs
BEGIN
    SELECT RAISE(ABORT, 'monthly_report_runs is append-only (delete blocked)');
END;
SQL);

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_email_outbox_events_no_update
BEFORE UPDATE ON email_outbox_events
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'email_outbox_events is append-only (update blocked)'
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_email_outbox_events_no_delete
BEFORE DELETE ON email_outbox_events
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'email_outbox_events is append-only (delete blocked)'
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_monthly_report_runs_no_update
BEFORE UPDATE ON monthly_report_runs
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'monthly_report_runs is append-only (update blocked)'
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_monthly_report_runs_no_delete
BEFORE DELETE ON monthly_report_runs
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'monthly_report_runs is append-only (delete blocked)'
SQL);

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_email_outbox_events_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'email_outbox_events is append-only (% blocked)', lower(TG_OP);
END;
$$;
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_email_outbox_events_no_update
BEFORE UPDATE ON email_outbox_events
FOR EACH ROW
EXECUTE FUNCTION forbid_email_outbox_events_mutation()
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_email_outbox_events_no_delete
BEFORE DELETE ON email_outbox_events
FOR EACH ROW
EXECUTE FUNCTION forbid_email_outbox_events_mutation()
SQL);

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_monthly_report_runs_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'monthly_report_runs is append-only (% blocked)', lower(TG_OP);
END;
$$;
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_monthly_report_runs_no_update
BEFORE UPDATE ON monthly_report_runs
FOR EACH ROW
EXECUTE FUNCTION forbid_monthly_report_runs_mutation()
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_monthly_report_runs_no_delete
BEFORE DELETE ON monthly_report_runs
FOR EACH ROW
EXECUTE FUNCTION forbid_monthly_report_runs_mutation()
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_email_outbox_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_email_outbox_events_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_monthly_report_runs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_monthly_report_runs_no_delete');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_email_outbox_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_email_outbox_events_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_monthly_report_runs_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_monthly_report_runs_no_delete');

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_email_outbox_events_no_update ON email_outbox_events');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_email_outbox_events_no_delete ON email_outbox_events');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_monthly_report_runs_no_update ON monthly_report_runs');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_monthly_report_runs_no_delete ON monthly_report_runs');
            DB::unprepared('DROP FUNCTION IF EXISTS forbid_email_outbox_events_mutation()');
            DB::unprepared('DROP FUNCTION IF EXISTS forbid_monthly_report_runs_mutation()');
        }
    }
};