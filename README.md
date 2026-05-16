# LaVita Urenregistratie

Laravel-backend voor het LaVita urenregistratie- en compliance-platform.

## Werkmap

Alle development, tests en deployment verlopen vanuit [laravel-rebuild/](laravel-rebuild/).

De README aldaar bevat alle instructies:
- Installatie en configuratie
- API-overzicht en rollenmatrix
- Testen
- Deployment naar Cloud86/Plesk

## Documentatie

| Document | Inhoud |
|----------|--------|
| [docs/api-referentie.md](docs/api-referentie.md) | Volledige API-referentie |
| [docs/deployment.md](docs/deployment.md) | Deployment-gids Cloud86/Plesk |
| [docs/lokale-ontwikkeling.md](docs/lokale-ontwikkeling.md) | Lokale ontwikkelgids |
| [docs/architectuur.md](docs/architectuur.md) | Systeemarchitectuur |
| [docs/ops-24-7.md](docs/ops-24-7.md) | Operationeel runbook |
| [docs/audit-rapport-11mei2026.md](docs/audit-rapport-11mei2026.md) | Auditrapport |
| [docs/governance/](docs/governance/) | Uitvoeringsdossiers iteraties 1–27 |

## Snelle start

```bash
cd laravel-rebuild
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test
```
