# APQ Cleaning Service

A mobile-first app for APQ Cleaning Service (Northern Beaches, Sydney) covering staff timesheets, scheduling, SMS reminders and Xero invoicing.

**Stack:** Laravel 12 · PHP 8.4 (8.3+ supported) · MariaDB · Blade + Alpine.js · Tailwind CSS v4 · cPanel hosting

## Status

**Phase 0 (foundations) is done:**
- APQ brand theme, branded login and password reset
- EN/ES switching on every screen
- Admin and staff roles, instant account lock with force-logout
- Staff profile limited to safe fields
- Admin staff management
- Staff import from the Weekly Planner

**Phase 1 (clients and scheduling) is done:**
- **Clients:** create and edit clients (fixed price per visit by default, Cash/ABN payment type). Access notes and "focus on" notes are shown to assigned cleaners only.
- **Regular schedules:** weekly, fortnightly, every 3 or 4 weeks, or monthly (nth weekday). The nightly `apq:appointments:generate` command creates visits 8 weeks ahead and keeps 9:00am at 9:00am across daylight saving.
- **Edits:** editing a schedule rebuilds future visits nobody has touched. Hand-edited visits are kept.
- **Admin week board:** filter by cleaner, add one-off visits, see unassigned visits, and get double-booking warnings.
- **Staff screens:** real Today and Week lists, and a job page with address, map link, access instructions and team.
- **Pay rates:** optional Saturday and Sunday rates per cleaner, falling back to their normal rate.
- **Client import:** `apq:import:clients` reads the client CSV. It skips rows marked "Cancelled" (`--include-cancelled` imports them as inactive), detects suburbs, normalises phones and flags invalid emails.

**Phase 2 (job timer and timesheets) is done:**
- **Cleaner timer:** START → STOP → SUBMIT. The start time is saved on the server, so closing the browser or losing signal never loses the timer. The database allows only one running timer per cleaner, and double taps do nothing. Optional GPS on start.
- **Review screen:** big start/end/total, an "Adjust times" option (original times are kept for the office), notes, then Submit. The timesheet is locked after submitting.
- **Status:** a "job running" banner on every cleaner screen. A visit is completed once every assigned cleaner has submitted.
- **Watchdog:** `apq:timers:watchdog` stops timers left running overnight at the scheduled end and flags them. Locking a cleaner stops their timer.
- **Admin timesheets:** grid for the current or previous fortnightly pay period with filters, scheduled vs logged variance, labour cost and totals. A missing-timesheets list with "Add time", bulk approve, edit with a reason (audited), send back to the cleaner, and stop a running timer.
- **Approval:** freezes the visit price and the cleaner's pay rate for that day (Saturday/Sunday rates applied).
- **Dashboard:** visits today, awaiting approval, and live timers with "running long" alerts.

**Phase 3 (Xero invoicing) is done:**
- **Connecting Xero:** admins connect it with OAuth 2.0. Tokens are encrypted and refresh automatically, with a weekly keep-alive. **Test connection** checks the organisation and the account codes.
- **Invoice runs:** pick a period and preview per client, with warnings for unapproved time, missing prices and missing emails. Then create invoices as Draft or Approved, optionally emailed through Xero. One line per visit (fixed price, or hourly × billable time), GST exclusive plus 10% GST, and per-visit clients get one invoice per visit.
- **Locking and retries:** invoiced timesheets are locked. Pushes are queued and rate-limited, and retries reuse the same Idempotency-Key, so a retry never duplicates an invoice in Xero. Failed invoices show Xero's message and can be retried.
- **Corrections and payments:** void & rebuild unlocks the timesheets (drafts are deleted in Xero, approved invoices voided; paid ones need a credit note). Payment status updates through the webhook, with an hourly sync as backup.

**Phase 4 (cancellations and SMS) is done:**
- **Cancellation monitor:** covers the next 48 hours, with countdown chips (amber "fee applies" inside the client's window).
- **Cancel dialog:** records the reason and the notice time, which can be backdated. It suggests the fee from the client's rule (%, fixed or none), then charges it as its own Xero invoice (account 260) or waives it with a reason. It can also send the client and cleaners an SMS.
- **SMS through Mobile Message:**
  - Day-before reminders go out from 16:00, only between 08:00 and 20:00, never twice, and respect opt-outs.
  - Manual reminders and free-text messages.
  - Templates are editable in English and Spanish.
  - A delivery log, with the delivery-receipt and inbound webhooks (a STOP reply opts the client out).
- **Test mode:** with `SMS_ENABLED=false`, messages are logged only.
- **What's new:** an admin-only changelog (`CHANGELOG.md`), linked at the bottom left of the admin menu.

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

## Staging deploys

Pushes deploy automatically to **https://apqcleaner.spinstaging.com** through GitHub Actions once it is set up. See [docs/DEPLOY-STAGING.md](docs/DEPLOY-STAGING.md).

## Production first run

1. Set `APQ_ADMIN_EMAIL` (and optionally `APQ_ADMIN_PASSWORD`) in `.env`.
2. Run:

```bash
php artisan migrate --force && php artisan db:seed --force
```

3. Import staff and clients. Without `--commit` each command is a dry run:

```bash
php artisan apq:import:staff storage/import/weekly-planner.xlsx --commit
```

```bash
php artisan apq:import:clients storage/import/clients.csv --commit
```

4. In the cPanel cron settings, add `* * * * * php /path/to/artisan schedule:run`. This creates upcoming visits every night.

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
