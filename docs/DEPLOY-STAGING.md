# Staging deployment: apqcleaner.spinstaging.com

Every push to the default branch (or `staging` / `main`) runs the tests on GitHub. If they pass, GitHub then:

1. Builds the app with PHP 8.4 and Node (production Composer packages and compiled CSS/JS).
2. Puts the site in maintenance mode.
3. Uploads the files to cPanel over SSH (rsync).
4. Runs migrations and rebuilds caches.
5. Brings the site back and checks `https://apqcleaner.spinstaging.com/up`.

The workflow is `.github/workflows/deploy-staging.yml`. You can also run it by hand from **GitHub → Actions → deploy-staging → Run workflow**.

The server never needs Git, Node or Composer. It only needs PHP 8.4, SSH and rsync, which are standard on cPanel.

> In the steps below, replace `CPANELUSER` with the cPanel username of the account that owns `apqcleaner.spinstaging.com`.

---

## One-time setup

### 1. Domain and document root

The app lives in `/home/CPANELUSER/apqcleaner`. Only its `public/` folder may be visible on the web.

1. In cPanel, go to **Domains**, find `apqcleaner.spinstaging.com` and choose **Manage**.
2. Set **Document Root** to `apqcleaner/public`, i.e. `/home/CPANELUSER/apqcleaner/public`.
3. In **SSL/TLS Status**, run **AutoSSL** for the domain.

> If the document root points at the app folder itself, `.env` (passwords and keys) could be downloaded. Always use `…/public`.

### 2. PHP 8.4

1. In **MultiPHP Manager**, set `apqcleaner.spinstaging.com` to **PHP 8.4**.
2. In **Select PHP Version / Extensions**, make sure these are enabled: `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `gd`, `exif`, `fileinfo`, `zip`, `curl`, `openssl`.
3. Find the PHP 8.4 command-line path. It is usually `/usr/local/bin/ea-php84` or `/opt/cpanel/ea-php84/root/usr/bin/php`. Check it in **Terminal** with:

```bash
ls /usr/local/bin/ea-php84 /opt/cpanel/ea-php84/root/usr/bin/php 2>/dev/null
```

### 3. Database

In **MySQL® Databases**:

1. Create a database, e.g. `CPANELUSER_apqstage`.
2. Create a user and give it **ALL PRIVILEGES** on that database.
3. Note the database name, username and password.

### 4. SSH deploy key

In cPanel, go to **SSH Access → Manage SSH Keys**:

1. **Generate a New Key**. Name it `github_deploy`, type RSA 4096. cPanel requires a passphrase: use a long random one and keep it for step 6.
2. **Manage** the new public key and click **Authorize**.
3. **View/Download** the **private** key. You will paste it into GitHub in step 6, then you can delete the downloaded copy.

The workflow unlocks the key with `STAGING_SSH_PASSPHRASE` on GitHub's runner only. The key on your server is unchanged, and the runner's copy is discarded after each run.

**Alternative (no passphrase):** create the key on your own computer, then use cPanel → SSH Access → **Import Key** to paste in the *public* key (`github_deploy.pub`), then **Authorize** it. Leave `STAGING_SSH_PASSPHRASE` unset.

```bash
ssh-keygen -t ed25519 -N "" -C github-deploy -f github_deploy
```

If **SSH Access** is missing, SSH is disabled for this account. Enable it for the account in WHM → **Modify an Account**, or ask your host.

Find the SSH host, port and host fingerprint. On your own computer, run:

```bash
ssh-keyscan -p 22 YOUR-SERVER-HOSTNAME
```

Use the real port if your host uses a non-standard one. Copy the whole output: it becomes `STAGING_SSH_KNOWN_HOSTS`. It lets GitHub verify it is talking to your server.

### 5. Create the server's `.env` (once)

Open **cPanel → Terminal** (or SSH in) and run:

```bash
mkdir -p ~/apqcleaner && cd ~/apqcleaner
```

Create `~/apqcleaner/.env` with **File Manager** or `nano .env`:

```dotenv
APP_NAME="APQ Cleaning Service"
APP_ENV=staging
APP_KEY=
APP_DEBUG=false
APP_URL=https://apqcleaner.spinstaging.com
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CPANELUSER_apqstage
DB_USERNAME=CPANELUSER_apqstage
DB_PASSWORD=the-password-from-step-3

SESSION_DRIVER=database
SESSION_LIFETIME=10080
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=daily

MAIL_MAILER=log

APQ_DISPLAY_TIMEZONE=Australia/Sydney
APQ_ADMIN_EMAIL=you@example.com
APQ_ADMIN_NAME="Adriana Palacio"
```

`APP_KEY` is filled in after the first deploy (step 8). `MAIL_MAILER=log` keeps staging from emailing anyone.

### 6. GitHub secrets and variables

In the repository, go to **Settings → Secrets and variables → Actions**.

**Secrets:**

| Name | Value |
|---|---|
| `STAGING_SSH_HOST` | Server hostname or IP (`43.250.141.96` works here) |
| `STAGING_SSH_PORT` | SSH port, usually `22` |
| `STAGING_SSH_USER` | `CPANELUSER` |
| `STAGING_SSH_KEY` | The whole private key from step 4, including the `-----BEGIN…` / `-----END…` lines |
| `STAGING_SSH_PASSPHRASE` | The key's passphrase from step 4 (leave unset if the key has none) |
| `STAGING_SSH_KNOWN_HOSTS` | The `ssh-keyscan` output from step 4 |
| `STAGING_APP_PATH` | `/home/CPANELUSER/apqcleaner` |

**Variables** (Variables tab):

| Name | Value |
|---|---|
| `STAGING_PHP_BIN` | PHP 8.4 path from step 2, e.g. `/usr/local/bin/ea-php84` |
| `STAGING_ENABLED` | `true`. Deploys are skipped until this is set. |

### 7. First deploy

Go to **Actions → deploy-staging → Run workflow**, or push any commit. Watch the run. If a step fails, its log explains why.

### 8. After the first deploy (once)

In **cPanel → Terminal**, generate the app key:

```bash
cd ~/apqcleaner && /usr/local/bin/ea-php84 artisan key:generate --force
```

Create the first administrator. A temporary password is printed, and you set your own at first login:

```bash
cd ~/apqcleaner && /usr/local/bin/ea-php84 artisan db:seed --force
```

Optionally, load the fictional demo data so testers have something to click through. Staging only:

```bash
cd ~/apqcleaner && /usr/local/bin/ea-php84 artisan db:seed --class=DemoSeeder --force
```

### 9. Cron (scheduler and queue)

In **cPanel → Cron Jobs**, add one job that runs **every minute**:

```
* * * * * cd /home/CPANELUSER/apqcleaner && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
```

This creates upcoming visits nightly, stops forgotten timers, and runs queued jobs.

---

## Real data on staging

To try the importers with the real spreadsheets:

1. Upload them to `~/apqcleaner/storage/import/`. Deploys never touch `storage/`, and the files are never committed to git.
2. Run each importer as a dry run first.

Staff:

```bash
cd ~/apqcleaner && /usr/local/bin/ea-php84 artisan apq:import:staff storage/import/weekly-planner.xlsx
```

Clients:

```bash
cd ~/apqcleaner && /usr/local/bin/ea-php84 artisan apq:import:clients storage/import/clients.csv
```

Add `--commit` to save.

## What a deploy never touches

These are excluded in `.github/deploy-exclude.txt`:

- `.env`
- `storage/` (uploads, logs, sessions, import files)
- `public/storage`

Development-only files (tests, docs, `node_modules`) are never uploaded.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Could not unlock STAGING_SSH_KEY` | `STAGING_SSH_PASSPHRASE` doesn't match the key. Re-enter it, with no extra spaces or newline. |
| `Permission denied (publickey)` | The key from step 4 isn't **Authorized** in cPanel, or `STAGING_SSH_USER` is wrong. |
| `Host key verification failed` | Re-run `ssh-keyscan` and update `STAGING_SSH_KNOWN_HOSTS`. |
| `.env is missing on the server` | Complete step 5. |
| `rsync: command not found` | Ask the host to enable rsync for jailshell accounts. |
| 500 error after deploy | Check `~/apqcleaner/storage/logs/laravel-*.log`. Usually `APP_KEY` isn't set (step 8) or the database details in `.env` are wrong. |
| Old styles or JS showing | Hard-refresh. Asset file names change on every build, so this clears itself. |
