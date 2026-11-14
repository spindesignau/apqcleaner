# APQ Cleaning Service — Implementation Blueprint

Target: **Laravel 12 · PHP 8.4 · MariaDB 10.11+/11.x · Livewire 3 · Tailwind v4 · cPanel (Apache/LiteSpeed)**

## 1. Packages

| Package | Purpose |
|---|---|
| `laravel/framework ^12` | core |
| `livewire/livewire ^3` | admin grids, schedule board, timer pages |
| `laravel/breeze` (Blade stack, installed then customised) | auth scaffolding, restyled to the APQ login |
| `xeroapi/xero-php-oauth2` | Xero Accounting API |
| `league/oauth2-client` | Xero auth code flow (`GenericProvider`) |
| `giggsey/libphonenumber-for-php-lite` | AU mobile normalisation (E.164) |
| `intervention/image ^3` | avatar resize/crop |
| `barryvdh/laravel-dompdf` | report PDFs |
| `spatie/laravel-backup` | nightly DB + storage backups |
| dev: `pestphp/pest`, `laravel/pint`, `larastan/larastan`, `barryvdh/laravel-debugbar` | quality |
| npm: `tailwindcss@4`, `@tailwindcss/vite`, `@tailwindcss/forms`, `sortablejs` | front end |

There is no Redis dependency, because shared hosting is the target. Cache, queue and sessions all use the **database** driver.

## 2. Directory structure

```
app/
  Actions/
    Timer/StartJob.php  StopJob.php  SubmitTimesheet.php  AdjustTimes.php
    Schedule/GenerateAppointments.php  CancelAppointment.php
    Staff/LockStaff.php  UnlockStaff.php
    Billing/CalculateBillableMinutes.php  BuildClientInvoice.php  CreateCancellationFeeInvoice.php
  Console/Commands/
    GenerateAppointmentsCommand.php   (apq:appointments:generate)
    TimerWatchdogCommand.php          (apq:timers:watchdog)
    SendSmsRemindersCommand.php       (apq:sms:send-reminders)
    SyncSmsStatusCommand.php          (apq:sms:sync-status)
    SmsBalanceCommand.php             (apq:sms:balance)
    XeroKeepaliveCommand.php          (apq:xero:keepalive)
    XeroSyncInvoicesCommand.php       (apq:xero:sync-invoices)
    ImportClientsCommand.php / ImportStaffCommand.php / ImportTimesheetsCommand.php
    LangCheckCommand.php              (apq:lang:check)
  Enums/ Role.php AppointmentStatus.php TimeEntryStatus.php EmploymentType.php PricingModel.php SmsStatus.php
  Http/
    Controllers/Auth/…                (Breeze, restyled)
    Controllers/LocaleController.php
    Controllers/Staff/TodayController.php WeekController.php JobController.php ProfileController.php
    Controllers/Admin/XeroAuthController.php
    Controllers/Webhooks/XeroWebhookController.php MobileMessageWebhookController.php
    Middleware/SetLocale.php EnsureUserIsActive.php EnsureRole.php ForcePasswordChange.php
    Requests/Staff/UpdateOwnProfileRequest.php UpdateOwnPasswordRequest.php SubmitTimesheetRequest.php
    Requests/Admin/StoreStaffRequest.php AdminUpdateStaffRequest.php StoreClientRequest.php …
  Livewire/Admin/
    Dashboard.php ScheduleBoard.php TimesheetGrid.php ClientIndex.php ClientForm.php StaffIndex.php
    StaffForm.php InvoiceRun.php CancellationMonitor.php SmsLog.php Reports/*.php Settings/*.php
  Models/ User Client ServiceType RecurringSchedule Appointment TimeEntry Cancellation Invoice
          InvoiceLine InvoiceRun SmsTemplate SmsMessage XeroConnection Setting AuditLog IntegrationLog
  Policies/ AppointmentPolicy TimeEntryPolicy ClientPolicy UserPolicy InvoicePolicy
  Services/
    Settings.php  Audit.php  PhoneNumber.php
    Xero/XeroOAuthProvider.php XeroTokenManager.php XeroContactService.php
    Sms/SmsGateway.php MobileMessageClient.php LogSmsGateway.php SmsDispatcher.php TemplateRenderer.php
  Jobs/ PushInvoiceToXero.php RefreshXeroInvoice.php SendSmsBatch.php
config/ xero.php mobilemessage.php apq.php import/clients.php
lang/ en/ es/ (auth, validation, passwords, pagination, staff, admin, sms, enums) + en.json es.json
resources/views/ layouts/{guest,staff,admin}.blade.php  components/{logo,lang-pill,job-card,job-timer,countdown-chip,badge}.blade.php
public/ manifest.webmanifest icons/ favicon.ico apple-touch-icon.png .htaccess
```

## 3. Routes

```php
// routes/web.php
Route::get('/locale/{locale}', LocaleController::class)->whereIn('locale', ['en', 'es'])->name('locale.switch');

require __DIR__.'/auth.php';                          // login (throttle:login), logout, password reset

Route::middleware(['auth', 'active', 'force.password'])->group(function () {
    Route::get('/', fn () => auth()->user()->isAdmin() ? to_route('admin.dashboard') : to_route('jobs.today'));

    // ---- Staff portal (admins may use it too) ----
    Route::get('/jobs/today', TodayController::class)->name('jobs.today');
    Route::get('/jobs/week/{week?}', WeekController::class)->name('jobs.week');          // ISO "2026-W46"
    Route::get('/jobs/{appointment}', [JobController::class, 'show'])->name('jobs.show');
    Route::post('/jobs/{appointment}/start',  [JobController::class, 'start'])->name('jobs.start');
    Route::post('/jobs/{appointment}/stop',   [JobController::class, 'stop'])->name('jobs.stop');
    Route::patch('/jobs/{appointment}/times', [JobController::class, 'adjust'])->name('jobs.adjust');
    Route::post('/jobs/{appointment}/submit', [JobController::class, 'submit'])->name('jobs.submit');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::get('/avatars/{user}', [ProfileController::class, 'avatar'])->name('avatar')->middleware('signed');

    // ---- Admin ----
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/', Admin\Dashboard::class)->name('dashboard');
        Route::get('/schedule', Admin\ScheduleBoard::class)->name('schedule');
        Route::get('/timesheets', Admin\TimesheetGrid::class)->name('timesheets');
        Route::get('/clients', Admin\ClientIndex::class)->name('clients');
        Route::get('/clients/{client}', Admin\ClientForm::class)->name('clients.edit');
        Route::get('/staff', Admin\StaffIndex::class)->name('staff');
        Route::get('/staff/{user}', Admin\StaffForm::class)->name('staff.edit');
        Route::post('/staff/{user}/lock', [Admin\StaffLockController::class, 'lock'])->name('staff.lock');
        Route::post('/staff/{user}/unlock', [Admin\StaffLockController::class, 'unlock'])->name('staff.unlock');
        Route::get('/invoices', Admin\InvoiceIndex::class)->name('invoices');
        Route::get('/invoices/run', Admin\InvoiceRun::class)->name('invoices.run');
        Route::get('/cancellations', Admin\CancellationMonitor::class)->name('cancellations');
        Route::get('/sms', Admin\SmsLog::class)->name('sms');
        Route::get('/reports/{report}', Admin\Reports\Show::class)->name('reports');
        Route::get('/settings/{tab?}', Admin\Settings\Index::class)->name('settings');
        Route::get('/xero/connect', [XeroAuthController::class, 'redirect'])->name('xero.connect');
        Route::get('/xero/callback', [XeroAuthController::class, 'callback'])->name('xero.callback');
        Route::post('/xero/disconnect', [XeroAuthController::class, 'disconnect'])->name('xero.disconnect');
    });
});

// Webhooks (CSRF-exempt in bootstrap/app.php)
Route::post('/webhooks/xero', XeroWebhookController::class);
Route::post('/webhooks/mobile-message/{token}/status',  [MobileMessageWebhookController::class, 'status']);
Route::post('/webhooks/mobile-message/{token}/inbound', [MobileMessageWebhookController::class, 'inbound']);
```

`bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $m) {
    $m->web(append: [SetLocale::class]);
    $m->alias(['active' => EnsureUserIsActive::class, 'role' => EnsureRole::class, 'force.password' => ForcePasswordChange::class]);
    $m->validateCsrfTokens(except: ['webhooks/*']);
    $m->trustProxies(at: '*');                     // LiteSpeed/Cloudflare in front
})
```

## 4. Key code

### 4.1 Locale

```php
final class SetLocale
{
    public function handle(Request $r, Closure $next)
    {
        $locale = $r->user()?->locale
            ?? $r->session()->get('locale')
            ?? $r->cookie('locale')
            ?? $r->getPreferredLanguage(['en', 'es'])
            ?? 'en';
        app()->setLocale(in_array($locale, ['en', 'es'], true) ? $locale : 'en');
        Carbon::setLocale(app()->getLocale());
        return $next($r);
    }
}

final class LocaleController
{
    public function __invoke(Request $r, string $locale)
    {
        $r->session()->put('locale', $locale);
        $r->user()?->forceFill(['locale' => $locale])->save();
        return back()->withCookie(cookie()->forever('locale', $locale));
    }
}
```

> Add `'locale'` to `EncryptCookies::except` so the pre-login cookie is readable without decryption issues.

### 4.2 Active-user guard and lockout

```php
final class EnsureUserIsActive
{
    public function handle(Request $r, Closure $next)
    {
        if ($r->user() && ! $r->user()->is_active) {
            Auth::guard('web')->logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            return to_route('login')->withErrors(['email' => __('auth.locked')]);
        }
        return $next($r);
    }
}

// LoginRequest::authenticate() — after Auth::attempt() succeeds:
if (! Auth::user()->is_active) {
    Auth::logout();
    throw ValidationException::withMessages(['email' => __('auth.locked')]);
}

final class LockStaff
{
    public function __invoke(User $staff, User $admin, ?string $reason): void
    {
        DB::transaction(function () use ($staff, $admin, $reason) {
            $staff->forceFill([
                'is_active' => false, 'locked_at' => now(), 'locked_by' => $admin->id,
                'lock_reason' => $reason, 'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $staff->id)->delete();   // immediate force-logout

            $staff->timeEntries()->where('status', 'running')->each(
                fn (TimeEntry $e) => app(StopJob::class)($e, auto: true, byAdmin: true));

            Audit::log('user.locked', $staff, after: ['reason' => $reason]);
        });
    }
}
```

### 4.3 Protected profile updates

```php
// app/Models/User.php
protected $fillable = ['name', 'email', 'password', 'avatar_path', 'locale'];   // NOT role/is_active/hourly_rate/…
protected $hidden   = ['password', 'remember_token', 'hourly_rate', 'notes'];
protected function casts(): array {
    return ['is_active' => 'bool', 'hourly_rate' => 'decimal:2', 'role' => Role::class,
            'employment_type' => EmploymentType::class, 'password' => 'hashed', 'locked_at' => 'datetime'];
}

// app/Http/Requests/Staff/UpdateOwnProfileRequest.php
public function rules(): array
{
    return [
        'name'   => ['required', 'string', 'max:120'],
        'email'  => ['required', 'email:rfc,dns', 'max:190', Rule::unique('users')->ignore($this->user())],
        'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        'locale' => ['required', Rule::in(['en', 'es'])],
    ];
}

// ProfileController::update
$data = $request->safe()->only(['name', 'email', 'locale']);   // whitelist — anything else is ignored
if ($request->hasFile('avatar')) $data['avatar_path'] = $avatars->store($request->user(), $request->file('avatar'));
if ($data['email'] !== $request->user()->email) $data['email_verified_at'] = null;
$request->user()->forceFill($data)->save();

// UpdateOwnPasswordRequest: current_password:web, Password::defaults(), confirmed
```

Staff Blade views never render `hourly_rate`, `employment_type`, `notes` or `is_active`. A feature test asserts that the response HTML does not contain the rate value.

### 4.4 Timer actions

```php
final class StartJob
{
    public function __invoke(Appointment $appt, User $user, ?array $gps = null): TimeEntry
    {
        Gate::forUser($user)->authorize('start', $appt);   // assigned, not cancelled, within window

        if ($existing = $appt->timeEntries()->where('user_id', $user->id)->first()) {
            return $existing;                                // idempotent double tap / resume
        }

        try {
            return DB::transaction(function () use ($appt, $user, $gps) {
                $entry = TimeEntry::create([
                    'appointment_id' => $appt->id, 'user_id' => $user->id, 'client_id' => $appt->client_id,
                    'status' => TimeEntryStatus::Running, 'running_lock' => $user->id,
                    'started_at' => now(), 'start_lat' => $gps['lat'] ?? null, 'start_lng' => $gps['lng'] ?? null,
                ]);
                if ($appt->status === AppointmentStatus::Scheduled) $appt->update(['status' => AppointmentStatus::InProgress]);
                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['timer' => __('staff.stop_current_job_first')]);
        }
    }
}

final class StopJob
{
    public function __invoke(TimeEntry $e, bool $auto = false, bool $byAdmin = false): TimeEntry
    {
        if ($e->status !== TimeEntryStatus::Running) return $e;   // idempotent
        $end = $auto ? min(now(), $e->appointment->scheduled_end->max($e->started_at)) : now();
        $secs = $e->started_at->diffInSeconds($end);
        $mins = (int) round($secs / 60);
        $e->update([
            'ended_at' => $end, 'duration_seconds' => $secs, 'duration_minutes' => $mins,
            'billable_minutes' => app(CalculateBillableMinutes::class)($e->client, $mins),
            'status' => TimeEntryStatus::Stopped, 'running_lock' => null, 'auto_stopped' => $auto,
        ]);
        return $e;
    }
}

final class CalculateBillableMinutes
{
    public function __invoke(Client $c, int $logged): int
    {
        $inc = max(1, $c->billing_increment_minutes);
        return max((int) (ceil($logged / $inc) * $inc), $c->minimum_billable_minutes);
    }
}
```

### 4.5 Policies

```php
final class AppointmentPolicy
{
    public function view(User $u, Appointment $a): bool  { return $u->isAdmin() || $a->staff->contains($u); }
    public function start(User $u, Appointment $a): Response
    {
        if (! $a->staff->contains($u)) return Response::denyAsNotFound();
        if (! in_array($a->status, [AppointmentStatus::Scheduled, AppointmentStatus::InProgress], true))
            return Response::deny(__('staff.job_not_startable'));
        $local = $a->scheduled_start->tz(settings('display_timezone'));
        if (now()->lt($a->scheduled_start->subMinutes(settings('timer.start_early_minutes'))) || ! now()->tz($local->tz)->isSameDay($local))
            return Response::deny(__('staff.job_not_today'));
        return Response::allow();
    }
}

final class TimeEntryPolicy
{
    public function update(User $u, TimeEntry $e): bool
    {
        if ($e->invoice_line_id) return false;
        if ($u->isAdmin()) return true;
        return $e->user_id === $u->id && in_array($e->status, [TimeEntryStatus::Running, TimeEntryStatus::Stopped, TimeEntryStatus::Rejected], true);
    }
}
```

## 5. Scheduler — `routes/console.php`

```php
Schedule::command('apq:appointments:generate')->dailyAt('01:00')->timezone('Australia/Sydney')->withoutOverlapping();
Schedule::command('apq:timers:watchdog')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('apq:sms:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('apq:sms:sync-status')->everyFifteenMinutes();
Schedule::command('apq:sms:balance')->dailyAt('07:00')->timezone('Australia/Sydney');
Schedule::command('apq:xero:sync-invoices')->hourly();
Schedule::command('apq:xero:keepalive')->weekly();
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=5')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('model:prune')->daily();
Schedule::command('backup:clean')->dailyAt('02:00');
Schedule::command('backup:run --only-db')->dailyAt('02:30');
Schedule::command('queue:prune-batches --hours=168')->daily();
```

Use `->timezone('Australia/Sydney')` on time-of-day schedules, because the app timezone is UTC.

## 6. Translations

- `lang/en/staff.php` / `lang/es/staff.php`, for example:

```php
// es
return [
  'today_jobs' => 'Trabajos de hoy', 'weekly_schedule' => 'Horario semanal', 'my_profile' => 'Mi perfil',
  'start_job' => 'INICIAR TRABAJO', 'stop_job' => 'DETENER TRABAJO', 'submit_timesheet' => 'ENVIAR HOJA DE HORAS',
  'job_notes' => 'Notas / comentarios del trabajo', 'job_notes_placeholder' => 'Ej.: horno limpiado, falta limpiavidrios',
  'total_minutes' => 'Total :minutes min', 'stop_current_job_first' => 'Primero detén tu trabajo actual.',
  'confirm_stop' => '¿Detener el trabajo de :client?', 'map' => 'Mapa', 'in_progress' => 'EN CURSO',
  'submitted' => 'Enviado', 'adjust_times' => 'Ajustar horas', 'no_jobs_today' => 'No tienes trabajos hoy ✨',
];
// lang/es/auth.php
'locked' => 'Tu cuenta ha sido desactivada. Por favor contacta a APQ Cleaning Service.',
'failed' => 'Estas credenciales no coinciden con nuestros registros.',
'throttle' => 'Demasiados intentos. Inténtalo de nuevo en :seconds segundos.',
```

- Laravel's own `validation`, `passwords` and `pagination` files for Spanish come from `laravel-lang/lang` (dev dependency, published once).
- `apq:lang:check` diffs keys between `en` and `es` and runs in CI.
- Spanish staff copy is informal (`tú`, as in Latin-American-friendly usage). Client SMS use formal `usted`.

## 7. cPanel deployment

### 7.1 Layout

```
/home/apq/
  laravel/                 ← git clone / deploy target (NOT web-accessible)
    public/
  public_html → laravel/public   (symlink)  ── or, if symlinks are disallowed:
  public_html/index.php    ← copy of public/index.php with paths changed to ../laravel/…
```

### 7.2 Steps

1. cPanel → **MultiPHP Manager**: set PHP **8.4** for the domain. **MultiPHP INI**: `memory_limit=256M`, `upload_max_filesize=8M`, `max_execution_time=60`. Required extensions: `pdo_mysql, mbstring, intl, bcmath, gd/imagick, fileinfo, openssl, curl, zip`.
2. **MySQL Databases**: create the DB and user (MariaDB). Grant ALL on that DB.
3. **SSL/TLS**: AutoSSL (Let's Encrypt) for the domain.
4. SSH (or Terminal):

```bash
cd ~/laravel && composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
php artisan migrate --force && php artisan db:seed --class=ProductionSeeder   # settings, service types, templates, first admin
php artisan storage:link
npm ci && npm run build        # or build locally/CI and upload public/build
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

5. **Cron Jobs** (every minute):

```
* * * * * /usr/local/bin/ea-php84 /home/apq/laravel/artisan schedule:run >> /dev/null 2>&1
```

(Use the PHP 8.4 CLI binary path shown in cPanel; often `/usr/local/bin/ea-php84` or `/opt/cpanel/ea-php84/root/usr/bin/php`.)

### 7.3 `.env` (production essentials)

```dotenv
APP_NAME="APQ Cleaning Service"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.apqcleaning.com.au
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=apq_app
DB_USERNAME=apq_app
DB_PASSWORD=
SESSION_DRIVER=database
SESSION_LIFETIME=10080          # 7 days; staff stay signed in on their phone
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=smtp                # cPanel mailbox or Postmark/SES
LOG_CHANNEL=daily
# + XERO_* and MOBILEMESSAGE_* (see 03 / 04)
```

### 7.4 `public/.htaccess` additions

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} !=on
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
    # …Laravel default rules follow…
</IfModule>
<IfModule mod_headers.c>
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(self), camera=(), microphone=()"
    <FilesMatch "\.(css|js|png|svg|woff2|ico)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
    <FilesMatch "manifest\.webmanifest$">
        Header set Cache-Control "public, max-age=3600"
    </FilesMatch>
</IfModule>
AddType application/manifest+json .webmanifest
```

### 7.5 Deploys

GitHub Actions run Pint, Larastan, Pest and `npm run build`, then deploy over SSH: `git pull` → `composer install --no-dev` → `php artisan migrate --force` → `optimize` → `queue:restart`. Use `php artisan down --render=errors::503 --retry=30` around migrations that lock large tables.

## 8. Test plan (Pest)

| Area | Key tests |
|---|---|
| Auth | login EN/ES, lockout message localised, throttle after 5, locked user's existing session invalidated on next request, `remember_token` rotated |
| Profile | mass-assignment attempt (`role`, `hourly_rate`, `is_active`) ignored; avatar validation; email change resets verification; password needs current |
| Authorisation | staff cannot view/start another staff member's appointment (404), cannot see `/admin/*` (403), cannot edit a submitted entry |
| Timer | start idempotent; second concurrent start fails; stop computes minutes; DST boundary durations (first Sunday Oct/Apr); watchdog auto-stop |
| Billing | increments, minimums, fixed price, multi-cleaner sum, rate history by visit date, GST rounding |
| Scheduling | generator idempotent; fortnightly parity from anchor; edits don't touch detached/started appointments; DST keeps 9:00 local |
| Cancellation | fee eligibility by notice hours and reason; waive requires reason; fee invoice unique per appointment |
| Xero | token refresh under lock; idempotency key reused on retry; 400 → failed no retry; 429 honours Retry-After; webhook signature verified |
| SMS | reminder selection windows; quiet hours; opt-out honoured; `custom_ref` uniqueness; webhook token; STOP/PARAR handling |
| i18n | `apq:lang:check` no missing keys; Carbon dates localised |
| Browser (Playwright, CI) | iPhone 14 viewport: login → start → reload → timer continues → stop → submit |

## 9. Delivery phases

| Phase | Scope | Exit criteria |
|---|---|---|
| **0. Foundations** (wk 1) | Laravel 12 skeleton, theme, logo components, layouts, auth restyle, EN/ES, roles, lockout, profile | Staff can log in (EN/ES), edit allowed profile fields only; admin can lock/unlock |
| **1. Clients, staff and scheduling** (wk 2–3) | Client/staff CRUD, rates, recurring schedules, generator, schedule board, staff Today/Week views, data import from the two spreadsheets | Real client list imported; next 8 weeks of visits visible to each cleaner |
| **2. Timer and timesheets** (wk 3–4) | START/STOP/SUBMIT, adjust, watchdog, admin timesheet grid, approval | Pilot with 2 cleaners for one week in parallel with the spreadsheet; minutes reconcile |
| **3. Xero** (wk 5) | OAuth connect, contact matching, invoice builder, batch runs, payment sync | One month invoiced from the app in the Xero Demo Company, then live as Draft |
| **4. Cancellations and SMS** (wk 6) | Countdown chips, cancellation monitor, fee invoices, Mobile Message reminders, webhooks, SMS log | Day-before reminders running live; a fee invoice generated end-to-end |
| **5. Reports and hardening** (wk 7) | Reports, CSV/PDF exports, backups, audit log UI, PWA install, accessibility pass, load test | Acceptance criteria (spec §9) all pass; go-live |

### 9.1 Progress and design changes

| Phase | Status | Notes |
|---|---|---|
| 0. Foundations | ✅ Done | Blade + Alpine only. Livewire is not installed yet, because forms are plain posts. It will be added in Phase 2 for the inline timesheet grid. |
| 1. Clients and scheduling | ✅ Done | **Price snapshot:** each appointment stores `pricing_model` + `price` when it is created, instead of keeping separate `client_rate_histories`. A price change only affects visits generated afterwards. **No drag-and-drop:** the week board uses edit forms, not drag-and-drop. **Weekend rates:** `users.saturday_rate` / `sunday_rate` (owner confirmed Saturday rates; Sunday rates appear in the planner too). |
| 2. Timer and timesheets | ✅ Done | **No Livewire:** the admin grid uses plain forms (bulk approve plus an edit page per entry), not inline editing. **No end-of-day auto-stop job:** the watchdog runs every 15 minutes and auto-stops timers from a previous day. **No overrun SMS yet:** long-running timers show on the dashboard; the SMS nudge comes in Phase 4. **Pay periods:** fortnights are counted from the `pay_period_anchor` setting (Mon 5 Jan 2026). |
| 3. Xero | ✅ Done | **No SDK:** Xero's REST API is called through Laravel's HTTP client. **No `Bus::batch`:** invoice runs dispatch one queued job per invoice and show progress by polling. **Contact matching:** automatic (by email, then name, else create); there is no manual contact picker yet. |
| 4. Cancellations & SMS | ✅ Done | **Mobile Message request format not yet checked:** it is in `MobileMessageGateway` only, because their docs were unreachable from the build environment, so verify it before setting `SMS_ENABLED=true`. **Cancellation fees** are always a separate invoice (no "add to next invoice" option). **No overrun SMS nudge to cleaners** yet; overruns show on the dashboard. **No SMS balance check** command. |
| 5. Reports & launch | Next | |

## 10. Open questions for the owner

Answered 14 Nov 2026:
- **All** clients are invoiced through Xero, Cash and ABN alike.
- Clients marked "Cancelled" are not imported.
- Saturday has its own pay rate.

Still open:


1. **Cancellation fee:** is it 50 % of the visit, a flat amount, or the full visit price? Does it apply per client or the same for everyone?
2. **Billing increments:** bill to the exact minute, or round up to 15 or 30 minutes? Is there a minimum (e.g. 2 h) per visit?
3. **Invoice cadence:** per visit, weekly, or monthly per client? Draft or Authorised by default?
4. **Pay period** for staff hours reporting: weekly or fortnightly? Is there a labour on-cost % to use?
5. **SMS sender:** alphanumeric `APQClean` (no replies) or a dedicated number (two-way, recommended)? What time should reminders go out?
6. **Xero account codes** for cleaning revenue and cancellation fees, and the invoice branding theme.
7. **Spreadsheet column mapping:** share the two sheets as `.xlsx`/`.csv` files (or allow `docs.google.com` in this environment) so the importers can be mapped exactly.
8. **Domain** for the app (e.g. `app.apqcleaning.com.au`) and the cPanel host details.
