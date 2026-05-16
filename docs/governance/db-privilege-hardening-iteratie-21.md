# DB Privilege Hardening — Iteratie 21

## Doel

Append-only evidence-tabellen mogen door de runtime-applicatie alleen gelezen en gevuld worden, nooit gemuteerd of verwijderd.

Scope:

- `email_outbox_events`
- `monthly_report_runs`
- `system_job_runs`

## Principes

- runtime account: least privilege
- migratie account: DDL-rechten, niet gebruikt in runtime
- scheiding tussen applicatierol en DBA-beheerrol
- wijzigingen op privileges alleen via change-control

## MySQL / MariaDB (voorbeeld)

```sql
-- app_runtime heeft alleen DML die nodig is
REVOKE UPDATE, DELETE, DROP, ALTER, TRIGGER ON lavita_laravel.email_outbox_events FROM 'app_runtime'@'%';
REVOKE UPDATE, DELETE, DROP, ALTER, TRIGGER ON lavita_laravel.monthly_report_runs FROM 'app_runtime'@'%';
REVOKE UPDATE, DELETE, DROP, ALTER, TRIGGER ON lavita_laravel.system_job_runs FROM 'app_runtime'@'%';

GRANT SELECT, INSERT ON lavita_laravel.email_outbox_events TO 'app_runtime'@'%';
GRANT SELECT, INSERT ON lavita_laravel.monthly_report_runs TO 'app_runtime'@'%';
GRANT SELECT, INSERT ON lavita_laravel.system_job_runs TO 'app_runtime'@'%';

FLUSH PRIVILEGES;
```

## PostgreSQL (voorbeeld)

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE email_outbox_events FROM app_runtime;
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE monthly_report_runs FROM app_runtime;
REVOKE UPDATE, DELETE, TRUNCATE ON TABLE system_job_runs FROM app_runtime;

GRANT SELECT, INSERT ON TABLE email_outbox_events TO app_runtime;
GRANT SELECT, INSERT ON TABLE monthly_report_runs TO app_runtime;
GRANT SELECT, INSERT ON TABLE system_job_runs TO app_runtime;
```

## Validatiechecklist

1. Runtime account kan `INSERT` op evidence-tabellen uitvoeren.
2. Runtime account kan geen `UPDATE` en `DELETE` uitvoeren.
3. Runtime account kan geen triggers droppen of DDL uitvoeren.
4. Integrity-audit command blijft functioneel onder runtime-credentials.
5. Fouten op verboden mutaties worden gelogd en geobserveerd.

## Rollout

1. Voer privileges eerst uit op staging.
2. Run `integrity:email-evidence --fail-on-corruption` op staging.
3. Verifieer application health + testcritical paden.
4. Pas daarna productie uitrollen met voorafgaande DBA-goedkeuring.
