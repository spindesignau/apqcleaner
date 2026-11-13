# APQ Cleaning Service — Database Schema (MariaDB 10.11+ / 11.x)

Conventions:

- Engine InnoDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
- `id` = `BIGINT UNSIGNED AUTO_INCREMENT`. All tables have `created_at`/`updated_at` unless noted.
- **All `*_at` columns are UTC** (`config/app.php` `timezone = 'UTC'`). Display uses `Australia/Sydney` via the `display_timezone` setting.
- Money = `DECIMAL(10,2)` in AUD. Rates are GST-exclusive.
- Durations are stored in **seconds** (source of truth) and **minutes** (derived, for querying and reporting).
- Soft deletes on `users`, `clients`, `appointments`, `recurring_schedules`, so history and invoices are never orphaned.

## 1. Entity relationship overview

```
users (admin|staff) ─┬─< appointment_staff >─┬─ appointments >── clients ──1:1── xero_contact link
                     │                        │        │  └──< recurring_schedules >─< recurring_schedule_staff
                     └─< time_entries >───────┘        │
                              │                        ├─< sms_messages
                              └── invoice_lines >── invoices (Xero) ──< invoice_runs
appointments ──1:0..1── cancellations ──0..1── invoices (fee)
service_types ─< clients / appointments / recurring_schedules
settings (key/value) · xero_connections · audit_logs · integration_logs · sessions · jobs · failed_jobs
```

## 2. Tables

### 2.1 `users`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(120) | staff-editable |
| email | varchar(190) unique | staff-editable (must re-verify) |
| email_verified_at | timestamp null | |
| password | varchar(255) | staff-editable via current-password flow |
| avatar_path | varchar(255) null | staff-editable, private disk |
| locale | char(2) default `en` | `en`/`es`, staff-editable |
| role | enum(`admin`,`staff`) default `staff` | **admin only** |
| mobile | varchar(20) null | E.164, admin-editable (used for staff SMS) |
| hourly_rate | decimal(8,2) null | **admin only**, hidden from staff |
| employment_type | enum(`casual`,`part_time`,`full_time`,`contractor_abn`) default `casual` | **admin only** |
| abn | varchar(14) null | contractors |
| notes | text null | **admin only** |
| is_active | boolean default 1 | **admin only** |
| locked_at | timestamp null | |
| locked_by | FK users null | |
| lock_reason | varchar(255) null | |
| must_change_password | boolean default 0 | |
| last_login_at | timestamp null | |
| remember_token | varchar(100) null | |
| deleted_at | timestamp null | |

Index: `(role, is_active)`.

### 2.2 `sessions` (Laravel database session driver)

Standard Laravel table: `id` varchar PK, `user_id` FK null **indexed**, `ip_address`, `user_agent`, `payload` longtext, `last_activity` int indexed. It is used for force-logout (`DELETE WHERE user_id = ?`).

### 2.3 `service_types`

| Column | Type |
|---|---|
| id | PK |
| name_en, name_es | varchar(100) |
| default_hourly_rate | decimal(8,2) null |
| default_fixed_price | decimal(10,2) null |
| xero_item_code | varchar(30) null |
| xero_account_code | varchar(10) default `200` |
| is_active | bool default 1 |
| sort_order | smallint |

### 2.4 `clients`

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| display_name | varchar(150) | shown to staff, e.g. "Smith – Manly" |
| billing_name | varchar(150) | Xero contact name |
| first_name, last_name | varchar(80) null | |
| email | varchar(190) null | invoice email |
| mobile | varchar(20) null | E.164 `+614…` |
| phone_alt | varchar(20) null | |
| address_line1, address_line2 | varchar(190) | |
| suburb | varchar(80) | e.g. Manly, Dee Why, Mona Vale |
| state | char(3) default `NSW` | |
| postcode | char(4) | |
| lat, lng | decimal(10,7) null | geocoded, optional |
| access_notes | text null | keys, alarm, pets (**visible to assigned staff**) |
| admin_notes | text null | **admin only** |
| service_type_id | FK null | default service |
| pricing_model | enum(`hourly`,`fixed`) default `hourly` | |
| hourly_rate | decimal(8,2) null | overrides service type |
| fixed_price | decimal(10,2) null | |
| billing_increment_minutes | smallint default 15 | |
| minimum_billable_minutes | smallint default 0 | |
| cancellation_window_hours | smallint default 24 | |
| cancellation_fee_type | enum(`percent_of_visit`,`fixed`,`none`) default `percent_of_visit` | |
| cancellation_fee_value | decimal(8,2) default 50.00 | |
| invoice_frequency | enum(`per_visit`,`weekly`,`fortnightly`,`monthly`) default `monthly` | used by batch runs |
| preferred_language | char(2) default `en` | for client SMS |
| sms_opt_in | bool default 1 | |
| sms_opted_out_at | timestamp null | set by STOP reply or admin |
| show_phone_to_staff | bool default 0 | |
| xero_contact_id | char(36) null unique | Xero `ContactID` GUID |
| xero_synced_at | timestamp null | |
| is_active | bool default 1 | |
| deleted_at | timestamp null | |

Indexes: `(is_active, display_name)`, `mobile`, `email`, `suburb`.

### 2.5 `client_rate_histories`

`id, client_id FK, pricing_model, hourly_rate, fixed_price, effective_from date, created_by FK users`. Index `(client_id, effective_from)`.

### 2.6 `staff_rate_histories`

`id, user_id FK, hourly_rate, effective_from date, created_by`. Index `(user_id, effective_from)`.

### 2.7 `recurring_schedules`

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| client_id | FK | |
| service_type_id | FK null | |
| frequency | enum(`weekly`,`fortnightly`,`every_3_weeks`,`every_4_weeks`,`monthly_nth_weekday`) | |
| weekday | tinyint | 1 = Mon … 7 = Sun (ISO) |
| nth_week | tinyint null | for `monthly_nth_weekday` (1–4, −1 = last) |
| start_time | time | local Sydney wall-clock |
| duration_minutes | smallint | |
| anchor_date | date | first occurrence (sets the fortnight parity) |
| effective_until | date null | |
| default_job_notes | text null | |
| is_active | bool | |
| deleted_at | | |

`recurring_schedule_staff`: `recurring_schedule_id, user_id` (composite PK).

> Wall-clock times are stored as local `time`, **not UTC**, so a 9:00 am clean stays 9:00 am across daylight-saving changes. Generated appointments convert to UTC per occurrence.

### 2.8 `appointments`

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| client_id | FK | |
| recurring_schedule_id | FK null | |
| occurrence_date | date null | |
| service_type_id | FK null | |
| scheduled_start | datetime (UTC) | |
| scheduled_end | datetime (UTC) | |
| status | enum(`scheduled`,`in_progress`,`completed`,`cancelled`,`cancelled_late`,`no_access`,`invoiced`) | |
| job_notes | text null | instructions for this visit (staff-visible) |
| is_detached | bool default 0 | manually edited, so the generator won't overwrite it |
| reminder_sent_at | timestamp null | auto-reminder guard |
| confirmed_at | timestamp null | client replied to confirm (v2) |
| created_by | FK users null | |
| deleted_at | | |

- Unique: `(recurring_schedule_id, occurrence_date)`, so the generator is idempotent.
- Indexes: `(scheduled_start, status)`, `(client_id, scheduled_start)`.

`appointment_staff`: `appointment_id, user_id` (PK both), `notified_at` null. Index `(user_id, appointment_id)`.

### 2.9 `time_entries`

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| appointment_id | FK | |
| user_id | FK (staff) | |
| client_id | FK | denormalised for reporting |
| status | enum(`running`,`stopped`,`submitted`,`approved`,`rejected`,`invoiced`) | |
| **running_lock** | bigint unsigned null **UNIQUE** | `= user_id` while running, else NULL. Gives one running timer per staff member (MariaDB allows multiple NULLs in a unique index). |
| started_at | datetime(0) UTC | server clock |
| ended_at | datetime(0) UTC null | |
| duration_seconds | int unsigned null | |
| duration_minutes | smallint unsigned null | `round(seconds / 60)` |
| billable_minutes | smallint unsigned null | after rounding/min/override |
| staff_notes | text null | the "Job notes / comments" box |
| admin_notes | text null | |
| original_started_at, original_ended_at | datetime null | kept when staff/admin adjust |
| staff_adjusted | bool default 0 | |
| admin_adjusted | bool default 0 | |
| auto_stopped | bool default 0 | |
| start_lat, start_lng, end_lat, end_lng | decimal(10,7) null | optional GPS |
| charge_rate | decimal(8,2) null | snapshot at approval |
| pay_rate | decimal(8,2) null | snapshot at approval |
| submitted_at, approved_at | timestamp null | |
| approved_by | FK users null | |
| rejected_reason | varchar(255) null | |
| invoice_line_id | FK null | set when invoiced (locks the row) |
| is_legacy | bool default 0 | imported history, never invoiced |

- Unique: `(appointment_id, user_id)`. One entry per cleaner per visit; a re-start resumes it via the review screen.
- Indexes: `(user_id, started_at)`, `(client_id, status, started_at)`, `(status)`.
- Check: `CHECK (ended_at IS NULL OR ended_at >= started_at)`.

### 2.10 `cancellations`

| Column | Type |
|---|---|
| id | PK |
| appointment_id | FK unique |
| reason | enum(`client_cancelled`,`client_no_access`,`weather`,`staff_unavailable`,`other`) |
| reason_note | varchar(255) null |
| notice_given_at | datetime UTC |
| hours_notice | decimal(6,2) | computed `scheduled_start - notice_given_at` |
| fee_eligible | bool |
| fee_decision | enum(`charged`,`waived`,`not_applicable`,`pending`) |
| fee_amount_ex_gst | decimal(10,2) null |
| waive_reason | varchar(255) null |
| invoice_id | FK invoices null |
| cancelled_by | FK users |

### 2.11 `invoice_runs`

`id, period_from date, period_to date, mode enum('draft','authorised'), send_email bool, status enum('pending','processing','completed','completed_with_errors','failed'), invoices_count, errors_count, created_by, started_at, finished_at`.

### 2.12 `invoices`

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| invoice_run_id | FK null | |
| client_id | FK | |
| type | enum(`service`,`cancellation_fee`) | |
| reference | varchar(50) | e.g. `APQ-2026-11-SMITH` / `CXL-1234` |
| idempotency_key | char(36) unique | UUID sent as the Xero `Idempotency-Key` header |
| issue_date, due_date | date | |
| subtotal, gst, total | decimal(10,2) | local calculation |
| xero_invoice_id | char(36) null unique | |
| xero_invoice_number | varchar(30) null | e.g. `INV-0123` |
| xero_status | enum(`DRAFT`,`SUBMITTED`,`AUTHORISED`,`PAID`,`VOIDED`,`DELETED`) null | |
| xero_total, xero_amount_due | decimal(10,2) null | from Xero response |
| xero_online_url | varchar(255) null | |
| emailed_at | timestamp null | |
| sync_status | enum(`pending`,`pushing`,`synced`,`failed`) | |
| last_error | text null | |
| synced_at | timestamp null | |

`invoice_lines`: `id, invoice_id FK, appointment_id FK null, description varchar(4000), quantity decimal(8,4), unit_amount decimal(10,2), account_code, tax_type default 'OUTPUT', item_code null, line_amount decimal(10,2), sort`.

### 2.13 `sms_templates`

`id, key varchar(50) unique (reminder_day_before, reminder_manual, cancellation_notice, staff_job_cancelled, free_text), body_en text, body_es text, is_active`.

Placeholders: `{client_first_name} {date} {time} {day} {cleaner_names} {business_phone}`.

### 2.14 `sms_messages`

| Column | Type | Notes |
|---|---|---|
| id | PK | |
| direction | enum(`outbound`,`inbound`) | |
| client_id | FK null | |
| user_id | FK null | staff recipient |
| appointment_id | FK null | |
| template_key | varchar(50) null | |
| trigger | enum(`auto`,`manual`,`system`) | |
| to_number, from_number | varchar(20) | |
| body | text | |
| segments | tinyint null | |
| provider_message_id | varchar(64) null indexed | |
| custom_ref | varchar(64) unique | our idempotency ref, e.g. `apt-1234-rem-day-before` |
| status | enum(`queued`,`sent`,`delivered`,`failed`,`rejected`,`received`) | |
| status_detail | varchar(255) null | |
| cost_credits | decimal(8,2) null | |
| sent_by | FK users null | |
| sent_at, delivered_at | timestamp null | |

### 2.15 `xero_connections`

`id, tenant_id char(36), tenant_name, access_token text (encrypted cast), refresh_token text (encrypted), id_token text null (encrypted), expires_at datetime, scopes varchar(500), connected_by FK users, is_active bool`. One active row in practice.

### 2.16 `settings`

`key varchar(100) PK, value json`. Typed access via a `Settings` service with defaults, cached.

| Key | Default |
|---|---|
| `business.name` | "APQ Cleaning Service" |
| `business.phone` | — |
| `business.abn` | — |
| `display_timezone` | `Australia/Sydney` |
| `pay_period` | `fortnightly` (+ `pay_period_anchor`) |
| `labour_on_cost_percent` | 12.00 (employees), 0 for contractors |
| `sms.reminder_mode` | `day_before_at` |
| `sms.reminder_time` | `16:00` |
| `sms.reminder_hours_before` | 24 |
| `sms.quiet_start` / `sms.quiet_end` | `20:00` / `08:00` |
| `sms.sender_id` | `APQClean` |
| `xero.default_account_code` | `200` |
| `xero.cancellation_account_code` | `260` |
| `xero.payment_terms_days` | 7 |
| `xero.branding_theme_id` | null |
| `timer.start_early_minutes` | 60 |
| `timer.overrun_alert_hours` | 3 |
| `notify_staff_on_cancel` | true |

### 2.17 `audit_logs`

`id, user_id FK null, action varchar(60) (e.g. user.locked, time_entry.updated, invoice.pushed, appointment.cancelled, sms.sent), auditable_type, auditable_id, before json null, after json null, reason varchar(255) null, ip varchar(45), created_at`. Index `(auditable_type, auditable_id)`, `(action, created_at)`.

### 2.18 `integration_logs`

`id, service enum('xero','mobile_message'), direction enum('request','response','webhook'), endpoint, http_status smallint null, correlation_id, payload json (secrets redacted), duration_ms, created_at`. Pruned after 90 days (`model:prune`).

### 2.19 Framework tables

`password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` (Laravel defaults; `QUEUE_CONNECTION=database`).

## 3. Key migration excerpts

```php
// database/migrations/2026_11_13_000100_create_time_entries_table.php
Schema::create('time_entries', function (Blueprint $t) {
    $t->id();
    $t->foreignId('appointment_id')->constrained()->restrictOnDelete();
    $t->foreignId('user_id')->constrained()->restrictOnDelete();
    $t->foreignId('client_id')->constrained()->restrictOnDelete();
    $t->enum('status', ['running','stopped','submitted','approved','rejected','invoiced'])->default('running')->index();
    $t->unsignedBigInteger('running_lock')->nullable()->unique();   // one running timer per staff
    $t->dateTime('started_at');
    $t->dateTime('ended_at')->nullable();
    $t->unsignedInteger('duration_seconds')->nullable();
    $t->unsignedSmallInteger('duration_minutes')->nullable();
    $t->unsignedSmallInteger('billable_minutes')->nullable();
    $t->text('staff_notes')->nullable();
    $t->text('admin_notes')->nullable();
    $t->dateTime('original_started_at')->nullable();
    $t->dateTime('original_ended_at')->nullable();
    $t->boolean('staff_adjusted')->default(false);
    $t->boolean('admin_adjusted')->default(false);
    $t->boolean('auto_stopped')->default(false);
    foreach (['start_lat','start_lng','end_lat','end_lng'] as $c) $t->decimal($c, 10, 7)->nullable();
    $t->decimal('charge_rate', 8, 2)->nullable();
    $t->decimal('pay_rate', 8, 2)->nullable();
    $t->timestamp('submitted_at')->nullable();
    $t->timestamp('approved_at')->nullable();
    $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $t->string('rejected_reason')->nullable();
    $t->foreignId('invoice_line_id')->nullable()->constrained()->nullOnDelete();
    $t->boolean('is_legacy')->default(false);
    $t->timestamps();

    $t->unique(['appointment_id', 'user_id']);
    $t->index(['user_id', 'started_at']);
    $t->index(['client_id', 'status', 'started_at']);
});
DB::statement('ALTER TABLE time_entries ADD CONSTRAINT chk_te_times CHECK (ended_at IS NULL OR ended_at >= started_at)');
```

```php
// users: role & protected fields
Schema::table('users', function (Blueprint $t) {
    $t->string('avatar_path')->nullable();
    $t->char('locale', 2)->default('en');
    $t->enum('role', ['admin','staff'])->default('staff');
    $t->string('mobile', 20)->nullable();
    $t->decimal('hourly_rate', 8, 2)->nullable();
    $t->enum('employment_type', ['casual','part_time','full_time','contractor_abn'])->default('casual');
    $t->string('abn', 14)->nullable();
    $t->text('notes')->nullable();
    $t->boolean('is_active')->default(true);
    $t->timestamp('locked_at')->nullable();
    $t->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
    $t->string('lock_reason')->nullable();
    $t->boolean('must_change_password')->default(false);
    $t->timestamp('last_login_at')->nullable();
    $t->softDeletes();
    $t->index(['role', 'is_active']);
});
```

## 4. Integrity rules enforced in code (transactions)

| Rule | Where |
|---|---|
| Starting a timer requires the user to be in `appointment_staff`, the appointment status to be `scheduled`/`in_progress`, and the start to be within the allowed window | `StartJobAction` inside `DB::transaction` (catches `UniqueConstraintViolationException` on `running_lock` → "Stop your current job first") |
| Staff can edit an entry only while `running`/`stopped`/`rejected` | `TimeEntryPolicy::update` |
| Entries with `invoice_line_id` are immutable | Model `updating` guard + policy |
| An invoice is pushed only when all its entries are `approved` and `client.xero_contact_id` is set | `BuildClientInvoiceAction` |
| A cancellation fee is created at most once per appointment | `cancellations.appointment_id` unique + `invoices.idempotency_key` |
| An SMS reminder is sent once | `appointments.reminder_sent_at` + `sms_messages.custom_ref` unique |
