# Moving to the live site: apqcleaner.com.au

Staging (apqcleaner.spinstaging.com) keeps running as the place to try changes. The live site gets its own cPanel account, database, `.env` and GitHub secrets, plus a copy of staging's data.

Order matters: **set up the server → deploy the code → copy the data → reconnect Xero and Mobile Message → switch staging to test mode.**

## 1. Create the cPanel account (WHM)

- [ ] **WHM → Create a New Account:** domain `apqcleaner.com.au`. Note the **username** (written `CPANELUSER` below).
- [ ] **DNS:** at the domain registrar, point `apqcleaner.com.au` at this server. Use the server's nameservers, or an A record for `@` and `www` → `43.250.141.96` if it's the same server as staging. Wait until the domain loads the cPanel placeholder page.
- [ ] **Document root:** leave it as `/public_html`. cPanel doesn't allow changing it for the main domain, so the first live deploy turns `~/public_html` into a link to the app's `public` folder. The old folder is kept as `public_html.before-apq-…`. Leave `public_html` empty (cPanel's own `cgi-bin` and `.htaccess` are fine).
- [ ] **cPanel → SSL/TLS Status → Run AutoSSL**, so the padlock shows on `https://apqcleaner.com.au`.
- [ ] **MultiPHP Manager:** PHP **8.4** for the domain (do this *before* the first deploy, so the setting is carried over). Same extensions as staging (DEPLOY-STAGING step 2).
- [ ] **MySQL Databases:** create a new database and user (not staging's), and give the user **All privileges** on it.
- [ ] **SSH Access → Manage SSH Keys:** generate a key (a passphrase is fine), **Authorize** it, and copy the **private key**.
- [ ] **Email Accounts:** create e.g. `hello@apqcleaner.com.au`, so password-reset emails come from the business domain.

## 2. The live `.env`

In **File Manager**, create the folder `/home/CPANELUSER/apqcleaner` and a file `.env` inside it. Copy staging's `.env`, then change these lines:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://apqcleaner.com.au
APP_KEY=                       # leave empty: the first deploy creates it

DB_DATABASE=CPANELUSER_apq     # the new database
DB_USERNAME=CPANELUSER_apq
DB_PASSWORD="..."

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=mail.apqcleaner.com.au
MAIL_PORT=465
MAIL_USERNAME=hello@apqcleaner.com.au
MAIL_PASSWORD="..."
MAIL_FROM_ADDRESS=hello@apqcleaner.com.au

SMS_ENABLED=true
SMS_TEST_RECIPIENT=            # must be EMPTY on the live site
MOBILEMESSAGE_WEBHOOK_TOKEN=   # a NEW long random string (not staging's)
```

Keep the Mobile Message username/password/sender and the Xero client ID/secret the same as staging. Put any value containing spaces, `#` or `$` in "double quotes".

## 3. GitHub settings

In the repository: **Settings → Secrets and variables → Actions**.

- [ ] **Secrets:**

| Secret | Value |
| --- | --- |
| `PRODUCTION_SSH_HOST` | `43.250.141.96` (or the new server's address) |
| `PRODUCTION_SSH_PORT` | `2222` |
| `PRODUCTION_SSH_USER` | `CPANELUSER` |
| `PRODUCTION_SSH_KEY` | the private key from step 1 |
| `PRODUCTION_SSH_PASSPHRASE` | its passphrase (if it has one) |
| `PRODUCTION_SSH_KNOWN_HOSTS` | the same value as `STAGING_SSH_KNOWN_HOSTS` if it's the same server; otherwise the output of `ssh-keyscan -p 2222 <server>` |
| `PRODUCTION_APP_PATH` | `/home/CPANELUSER/apqcleaner` |

- [ ] **Variables:** `PRODUCTION_URL` = `https://apqcleaner.com.au`, `PRODUCTION_PHP_BIN` = `/usr/local/bin/ea-php84`, `PRODUCTION_ENABLED` = `true`.
- [ ] **Recommended:** Settings → Environments → **production** → *Required reviewers* (yourself). GitHub then asks for a click before anything touches the live site.

## 4. Deploy the code

- [ ] **Actions → deploy-production → Run workflow.** It checks `.env`, uploads the app, points `public_html` at it, creates `APP_KEY`, sets up the database, **installs the every-minute cron job**, and checks `/up` and `/login`.

Live deploys never happen on an ordinary push: only from this button, or by pushing a version tag (`git tag v1.0.0 && git push origin v1.0.0`).

## 5. Copy staging's data

- [ ] **Actions → copy-staging-to-production → Run workflow** and type `COPY`. It:
  1. Backs up staging and downloads the backup.
  2. Backs up the live database (a safety copy in `storage/app/private/backups`), then loads staging's data into it. Clients, cleaners, logins, schedules, timesheets, invoices, SMS history and settings all come across.
  3. Removes Xero from staging (staging can't create real invoices after this). Staging's data stays as it is.
- [ ] Sign in at `https://apqcleaner.com.au/login` with your **staging** email and password. Everyone, cleaners included, keeps their login, but has to sign in once on the new address.

Run it again any time before launch to refresh the live copy. After launch, **don't**: it would replace live data with staging's.

## 6. Reconnect the integrations on the live site

- [ ] **Xero** (Xero logins can't be copied between sites, because they're encrypted with each site's own key):
  1. developer.xero.com → your app → **Redirect URIs** → add `https://apqcleaner.com.au/admin/xero/callback`. Keep the staging one.
  2. Same app → **Webhooks** → change the delivery URL to `https://apqcleaner.com.au/webhooks/xero` (tick **Invoices** and **Contacts**). Put the webhook key in the live `.env` as `XERO_WEBHOOK_KEY`.
  3. Live site → Admin → Xero → **Connect to Xero** → choose the real organisation → **Test connection**.
- [ ] **Mobile Message:** Admin → SMS shows two webhook URLs for the live site. Paste them into Mobile Message (Settings → API/webhooks) in place of the staging ones. Then press **Check Mobile Message account**.
- [ ] **Admin → Settings → System status:** after two minutes, *Scheduled tasks* and *Background jobs* should be green.

## 7. Put staging into test mode

On staging, edit `/home/STAGINGUSER/apqcleaner/.env`:

```dotenv
SMS_TEST_RECIPIENT=+614XXXXXXXX   # your own mobile: every staging text goes there
```

Then run `artisan optimize:clear && artisan optimize` (see DEPLOY-STAGING → Troubleshooting), or just re-run deploy-staging.

Even without this, staging no longer texts real clients: with no test number it records the message as "not sent". To try invoicing on staging, connect it to Xero's **Demo Company**, never the real organisation.

## 8. Go-live checks

- [ ] `https://apqcleaner.com.au/login` loads with the padlock, in English, and the EN/ES switch works.
- [ ] A cleaner signs in on a phone and sees today's jobs. START → STOP → SUBMIT works on a test visit (delete it afterwards).
- [ ] Approve that timesheet, then create a one-client invoice as a **Draft** in Xero (delete it in Xero afterwards). Its status should change from *Sending…* within a minute.
- [ ] **Settings → Backups → Back up now** works. Download the file and keep it somewhere private.
- [ ] System status shows debug mode **Off**, and SMS shows "Sending for real".
- [ ] Tell the cleaners the new address, and have them re-add it to their phone's home screen.

## After launch

- **Weekly:** download a backup from Settings → Backups (an off-server copy), and glance at System status.
- **Fortnightly:** approve timesheets, then Reports → *Staff hours & pay* → CSV for payroll.
- **Monthly:** Invoices → *Create invoices*.
- **Changes:** try them on staging first (deploys automatically on every push), then run **deploy-production** when happy.
