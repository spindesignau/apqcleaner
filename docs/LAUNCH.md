# Launch checklist: staging → live site

This assumes staging (apqcleaner.spinstaging.com) is working and has been trialled. The live site is set up the same way as staging (see [DEPLOY-STAGING.md](DEPLOY-STAGING.md)), with its own database, `.env` and secrets. Staging stays as the place to try changes first.

> Replace `LIVE-DOMAIN` with the real address (e.g. `app.apqcleaning.com.au`) and `CPANELUSER` with the live cPanel account.

## 1. Before launch day

- [ ] **Trial on staging:** at least one full pay fortnight with real cleaners (START/STOP/SUBMIT) and one invoice run into the Xero **Demo Company**.
- [ ] **Confirm the owner's settings:**
  - The pay fortnight start (`pay_period_anchor`, currently Mon 5 Jan 2026).
  - Billing rounding for hourly clients (15 min, no minimum).
  - Default cancellation fee (50%, 24 h).
- [ ] **Clients:** prices, regular schedules, cancellation rules and mobiles checked. Run Reports → *Revenue by client* on staging as a sanity check.
- [ ] **Cleaners:** real emails, pay rates (including Saturday/Sunday) and mobiles.
- [ ] **Logo:** replace the placeholder logo in `brand/` and `public/icons/` with the designer's master files.

## 2. Server (cPanel)

- [ ] **Domain:** add `LIVE-DOMAIN`. Set the **document root to `/home/CPANELUSER/apqcleaner/public`** and run **AutoSSL**.
- [ ] **PHP 8.4** for the domain in MultiPHP Manager, with the extensions listed in DEPLOY-STAGING step 2.
- [ ] **Database:** a new database and user (do *not* reuse staging's).
- [ ] **SSH:** a key authorised for the live account, plus `ssh-keyscan -p <port> <host>` output for the known-hosts secret.
- [ ] **`.env`** at `~/apqcleaner/.env`. Copy from staging, then change:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://LIVE-DOMAIN
APP_KEY=                      # leave empty: the first deploy generates it
DB_DATABASE=...  DB_USERNAME=...  DB_PASSWORD="..."
SESSION_SECURE_COOKIE=true
MAIL_MAILER=smtp              # real mail settings, for password resets
MAIL_HOST=...  MAIL_PORT=465  MAIL_USERNAME=...  MAIL_PASSWORD="..."  MAIL_FROM_ADDRESS=hello@...
APQ_ADMIN_EMAIL=...           # the owner's login
SMS_ENABLED=true              # real SMS (leave false until the Mobile Message account is ready)
SMS_TEST_RECIPIENT=           # must be EMPTY in production
MOBILEMESSAGE_USERNAME=...  MOBILEMESSAGE_PASSWORD="..."  MOBILEMESSAGE_SENDER=...
MOBILEMESSAGE_WEBHOOK_TOKEN=<long random string>
MOBILEMESSAGE_WEBHOOK_SECRET=<from Mobile Message Settings > API>
XERO_CLIENT_ID=...  XERO_CLIENT_SECRET="..."  XERO_WEBHOOK_KEY=...
```

  Put any value containing spaces, `#` or `$` in "double quotes".

## 3. GitHub

- [ ] **Secrets** (Settings → Secrets and variables → Actions): `PRODUCTION_SSH_HOST`, `PRODUCTION_SSH_PORT`, `PRODUCTION_SSH_USER`, `PRODUCTION_SSH_KEY`, `PRODUCTION_SSH_PASSPHRASE` (if the key has one), `PRODUCTION_SSH_KNOWN_HOSTS`, `PRODUCTION_APP_PATH`.
- [ ] **Variables:** `PRODUCTION_URL=https://LIVE-DOMAIN`, `PRODUCTION_PHP_BIN=/usr/local/bin/ea-php84`, `PRODUCTION_ENABLED=true`.
- [ ] **Recommended:** Settings → Environments → **production** → *Required reviewers*. GitHub then waits for an owner's click before each live deploy.

Live deploys never happen on an ordinary push. Run **Actions → deploy-production → Run workflow**, or push a version tag:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

## 4. First deploy and setup

- [ ] Run **deploy-production**. It checks `.env`, uploads, generates `APP_KEY`, migrates and checks `/up` and `/login`.
- [ ] Create the owner's admin login (it prints a temporary password):

```bash
cd ~/apqcleaner && /usr/local/bin/ea-php84 artisan db:seed --force
```

- [ ] **Cron job:** every minute (this drives visits, reminders, invoices and backups):

```
* * * * * cd /home/CPANELUSER/apqcleaner && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
```

- [ ] **Admin → Settings → System status:** after a couple of minutes, "Scheduled tasks" and "Background jobs" should both be green.
- [ ] **Data:** import the staff (planner) and clients (Clients → Import from spreadsheet), or copy the trial data across from staging. To copy it, download a staging backup from Settings → Backups, then restore it:

```bash
gunzip -c apq-db-XXXX.sql.gz | mysql -u USER -p LIVE_DB
```

## 5. Integrations

- [ ] **Xero:**
  - At developer.xero.com, add `https://LIVE-DOMAIN/admin/xero/callback` to the app's **Redirect URIs** (keep the staging one).
  - In APQ, go to Settings → Xero → **Connect to Xero** and choose the **real organisation**.
  - **Test connection:** account codes 200 and 260 should be found.
  - Webhook (optional): `https://LIVE-DOMAIN/webhooks/xero` with its key in `XERO_WEBHOOK_KEY`.
- [ ] **Mobile Message:**
  - Settings → SMS → **Check Mobile Message account** should show the balance and an approved sender.
  - Set the two webhook URLs shown on that page in Mobile Message.
  - Add the **business phone** for the templates.
  - Send one reminder to your own mobile.

## 6. Go-live checks

- [ ] `https://LIVE-DOMAIN/login` loads over HTTPS (the padlock shows), in English, and the EN/ES switch works.
- [ ] A cleaner can sign in on a phone and see today's jobs. START → STOP → SUBMIT works on a test visit, which you then delete.
- [ ] An admin can approve that timesheet, and a one-client invoice run creates a **Draft** in the real Xero. Delete it in Xero afterwards.
- [ ] **Settings → Backups → Back up now** works. Download the file and keep it somewhere private.
- [ ] System status shows debug mode **Off**, and SMS shows "Sending for real" (or test mode, if not yet ready).

## 7. After launch

- **Weekly:** download a backup from Settings → Backups (an off-server copy), and glance at System status.
- **Fortnightly:** approve timesheets, then Reports → *Staff hours & pay* → CSV for payroll.
- **Monthly:** Invoices → *Create invoices* for the previous month, and Reports → *Margin by client* to spot under-priced clients.
- **Changes:** try them on staging first. Deploy to production when happy, then check **What's new** for the release notes.
