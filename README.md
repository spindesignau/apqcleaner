# APQ Cleaning Service

A mobile-first app for APQ Cleaning Service (Northern Beaches, Sydney) covering staff timesheets, scheduling, SMS reminders and Xero invoicing.

**Stack:** Laravel 12 · PHP 8.4 (8.3+ supported) · MariaDB · Blade + Alpine.js · Tailwind CSS v4 · cPanel hosting

## Status

**Phase 0 (foundations) is done:**
- APQ brand theme, logo components and PWA icons
- Branded login, logout and password reset
- EN/ES switching on every screen, including login
- Admin and staff roles
- Instant account lock with force-logout
- Forced change of temporary passwords
- Staff profile limited to name, photo, email, password and language
- Today/Week shells for staff
- Admin dashboard and staff management
- Staff import from the Weekly Planner

The next phases are listed in [docs/06-IMPLEMENTATION-BLUEPRINT.md §9](docs/06-IMPLEMENTATION-BLUEPRINT.md).

## Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# Quick start with SQLite: set DB_CONNECTION=sqlite and DB_DATABASE=/absolute/path/database/database.sqlite
php artisan migrate
php artisan db:seed --class=DemoSeeder   # demo accounts, password "password123"
php artisan serve
```

Demo accounts:

| Account | Use |
|---|---|
| `admin@demo.test` | Administrator |
| `cleaner@demo.test` | Staff, Spanish |
| `cleaner.en@demo.test` | Staff, English |
| `locked@demo.test` | Locked account (shows the lockout message) |

## Production first run

1. Set `APQ_ADMIN_EMAIL` (and optionally `APQ_ADMIN_PASSWORD`) in `.env`.
2. Run:

```bash
php artisan migrate --force && php artisan db:seed --force
```

3. Import staff from the Weekly Planner. Without `--commit` it is a dry run:

```bash
php artisan apq:import:staff storage/import/weekly-planner.xlsx --commit
```

Real business files go in `storage/import/`, which git ignores.

## Quality checks

```bash
php artisan test
./vendor/bin/pint --test
php artisan apq:lang:check
```

## Specification

1. [Product specification](docs/01-SPECIFICATION.md)
2. [Database schema](docs/02-DATABASE-SCHEMA.md)
3. [Xero integration](docs/03-XERO-INTEGRATION.md)
4. [Mobile Message SMS integration](docs/04-SMS-MOBILE-MESSAGE.md)
5. [UI theme, brand assets and screens](docs/05-UI-THEME.md)
6. [Implementation blueprint](docs/06-IMPLEMENTATION-BLUEPRINT.md)

The files in [`brand/`](brand) are a placeholder redraw of the APQ logo. Replace them with the designer's master files before launch.
