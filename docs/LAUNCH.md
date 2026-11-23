# Moving to the live site: apqcleaner.com.au

The live site is on a different host from staging: Synergy Wholesale shared hosting, server `syn02ee.syd7.hostyourservices.net`, IP `110.232.143.245`, cPanel user **`apqclean`**. Its firewall blocks SSH from outside, so GitHub can't push to it. It uses a **pull deploy** instead:

1. **GitHub → Actions → publish-release → Run workflow** tests and builds the app, and publishes it as a release.
2. Every 5 minutes, a cron job on the server checks for a new release. When it finds one, it backs up the database, installs the release, migrates, and checks the site answers. If the site returns an error, the previous version is put back automatically.
3. **Admin → Settings → System status** shows which release is live, or why the last one failed. The full log is `~/apqcleaner/storage/logs/deploy.log`.

Staging (apqcleaner.spinstaging.com) keeps deploying automatically on every push. Try changes there first.

## 1. cPanel (account `apqclean`)

- [ ] **Select PHP Version:** **8.4**. Tick the extensions `mbstring`, `intl`, `bcmath`, `gd`, `exif`, `zip`, `pdo_mysql`, `fileinfo`.
- [ ] **Document root:** leave it as `/public_html`, and leave that folder empty (cPanel's own `cgi-bin` and `.htaccess` are fine). The first install turns `~/public_html` into a link to the app's `public` folder. The old folder is kept as `public_html.before-apq-…`.
- [ ] **MySQL Databases:** create a database and a user, and give the user **All privileges** on the database. Names look like `apqclean_app`.
- [ ] **Email Accounts:** create `hello@apqcleaner.com.au` (password-reset emails come from it).
- [ ] **DNS:** at the registrar, `apqcleaner.com.au` and `www` → **110.232.143.245** (A records), or use the host's nameservers. Once it resolves, the SSL certificate (already "Active") covers it.

## 2. A read-only GitHub token for the server

GitHub → your avatar → **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**:

- **Name:** `apqcleaner.com.au deploy`
- **Expiration:** 1 year (put a reminder in your calendar to renew it)
- **Repository access:** *Only select repositories* → `spindesignau/apqcleaner`
- **Permissions → Repository → Contents:** **Read-only** (nothing else)

Copy the token (it starts with `github_pat_`). It goes **only** into the server's `.env` in the next step. Never paste it into chat or commit it.

## 3. The live `.env`

**File Manager** → create the folder `/home/apqclean/apqcleaner`. Inside it, create a file called `.env`, then copy staging's `.env` into it and change these lines:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://apqcleaner.com.au
APP_KEY=                        # leave empty: the first install creates it

DB_DATABASE=apqclean_app
DB_USERNAME=apqclean_app
DB_PASSWORD="..."

SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=mail.apqcleaner.com.au
MAIL_PORT=465
MAIL_USERNAME=hello@apqcleaner.com.au
MAIL_PASSWORD="..."
MAIL_FROM_ADDRESS=hello@apqcleaner.com.au

SMS_ENABLED=true
SMS_TEST_RECIPIENT=             # must be EMPTY on the live site
MOBILEMESSAGE_WEBHOOK_TOKEN=    # a NEW long random string (not staging's)

# Pull deploy
GITHUB_DEPLOY_REPO=spindesignau/apqcleaner
GITHUB_DEPLOY_TOKEN="github_pat_..."
APQ_PHP_BIN=/usr/local/bin/php
```

Keep the Mobile Message username/password/sender and the Xero client ID/secret the same as staging. Put any value containing spaces, `#` or `$` in "double quotes".

## 4. First release and install

- [ ] **GitHub → Actions → publish-release → Run workflow.** It takes about 3 minutes and ends with "Published live-…".
- [ ] **cPanel → Terminal**, paste this once. It downloads the deploy script using the token from `.env`, then runs it:

```bash
cd ~/apqcleaner && mkdir -p .deploy && T=$(grep -E '^GITHUB_DEPLOY_TOKEN=' .env | cut -d= -f2- | tr -d '" ') && curl -fsSL -H "Authorization: Bearer $T" -H "Accept: application/vnd.github.raw" https://api.github.com/repos/spindesignau/apqcleaner/contents/.github/scripts/pull-deploy.sh -o .deploy/pull-deploy.sh && bash .deploy/pull-deploy.sh
```

It ends with **"Release live-… installed."** It has also set up two cron jobs: the deploy check (every 5 minutes) and the app's scheduler (every minute). You can see both under cPanel → Cron Jobs.

From now on, releasing is only **Actions → publish-release → Run workflow** (or pushing a version tag). The live site picks it up within 5 minutes.

## 5. Copy staging's data

1. **Staging** → Admin → Settings → **Back up now**, then **download** the newest backup (`apq-db-….sql.gz`).
2. **Staging** → Admin → Xero → **Disconnect**. Staging must not be connected to the real organisation once the live site is.
3. **Live cPanel → File Manager** → upload the file into `/home/apqclean/apqcleaner/storage/app/private/`.
4. **Live cPanel → Terminal** (put in the real file name):

```bash
cd ~/apqcleaner && php artisan apq:backup && php artisan apq:restore storage/app/private/apq-db-XXXX.sql.gz --force && php artisan optimize:clear && php artisan optimize && rm storage/app/private/apq-db-XXXX.sql.gz
```

5. Sign in at `https://apqcleaner.com.au/login` with your **staging** email and password. Everyone, cleaners included, keeps their login, but signs in once on the new address.

You can repeat this before launch to refresh the live copy. After launch, **don't**: it replaces live data.

## 6. Connect the integrations on the live site

- [ ] **Xero:**
  1. developer.xero.com → your app → **Redirect URIs** → add `https://apqcleaner.com.au/admin/xero/callback`. Keep the staging one.
  2. **Webhooks** → change the delivery URL to `https://apqcleaner.com.au/webhooks/xero` (tick **Invoices** and **Contacts**). Put the webhook key in the live `.env` as `XERO_WEBHOOK_KEY`, then run `php artisan optimize` in Terminal.
  3. Live site → Admin → Xero → **Connect to Xero** → the real organisation → **Test connection**.
- [ ] **Mobile Message:** Admin → SMS shows the live webhook URLs. Paste them into Mobile Message in place of staging's, then press **Check Mobile Message account**.
- [ ] **Admin → Settings → System status:** after two minutes, *Scheduled tasks*, *Background jobs* and *Latest release* should all be green.

## 7. Staging into test mode

In staging's `.env`, set `SMS_TEST_RECIPIENT=+614XXXXXXXX` (your own mobile), then re-run deploy-staging. Without it, staging records texts as "not sent", so it can't text real clients either way. To try invoicing on staging, connect it to Xero's **Demo Company**, never the real organisation.

## 8. Go-live checks

- [ ] `https://apqcleaner.com.au/login` loads with the padlock, in English, and the EN/ES switch works.
- [ ] A cleaner signs in on a phone and sees today's jobs. START → STOP → SUBMIT works on a test visit (delete it afterwards).
- [ ] Approve that timesheet, then create a one-client invoice as a **Draft** in Xero (delete it in Xero afterwards). *Sending…* should clear within a minute.
- [ ] **Settings → Backups → Back up now** works. Download the file and keep it somewhere private.
- [ ] Tell the cleaners the new address, and have them re-add it to their phone's home screen.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| A release didn't arrive | Admin → Settings → *Latest release* shows why. Details are in `~/apqcleaner/storage/logs/deploy.log`. "Could not reach GitHub" means the token expired or is wrong: make a new one (step 2) and update `.env`. |
| Install a release now instead of waiting | Terminal: `bash ~/apqcleaner/.deploy/pull-deploy.sh` |
| Reinstall the current release | Terminal: `bash ~/apqcleaner/.deploy/pull-deploy.sh --force` |
| A release failed and was rolled back | The live site keeps running the previous version. Fix the problem on staging, then publish a new release. The failed one isn't retried. |
| Changed `.env` | Terminal: `cd ~/apqcleaner && php artisan optimize:clear && php artisan optimize` |
