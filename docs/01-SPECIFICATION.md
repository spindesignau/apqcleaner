# APQ Cleaning Service — Product Specification

| | |
|---|---|
| **Product** | APQ Cleaning Service — staff timesheet, scheduling, SMS reminder and Xero invoicing web app |
| **Business** | Domestic cleaning, Northern Beaches, Sydney (NSW, Australia) |
| **Owner** | Adriana Palacio |
| **Version** | 1.0 (build specification) |
| **Timezone** | `Australia/Sydney` for all display and scheduling; all timestamps stored in UTC |
| **Currency / Tax** | AUD, GST 10 % (prices entered **GST-exclusive**, Xero `LineAmountTypes = Exclusive`) |
| **Languages** | English (`en`, default) and Spanish (`es`), switchable by any user, including on the login screen |

Companion documents:

| Doc | Contents |
|---|---|
| [02-DATABASE-SCHEMA.md](02-DATABASE-SCHEMA.md) | MariaDB schema, Laravel migrations, indexes, integrity rules |
| [03-XERO-INTEGRATION.md](03-XERO-INTEGRATION.md) | OAuth 2.0 connection, contact sync, invoice and cancellation-fee generation, webhooks |
| [04-SMS-MOBILE-MESSAGE.md](04-SMS-MOBILE-MESSAGE.md) | Mobile Message SMS reminders (automatic and manual), delivery tracking, opt-out |
| [05-UI-THEME.md](05-UI-THEME.md) | Brand tokens, Tailwind theme, logo usage, PWA icons, screen wireframes |
| [06-IMPLEMENTATION-BLUEPRINT.md](06-IMPLEMENTATION-BLUEPRINT.md) | Code structure, routes, policies, services, jobs, scheduler, cPanel deployment, test plan, delivery phases |
| [`../brand/`](../brand) | Placeholder SVG/PNG logo, PWA icons and favicon |

---

## 1. Goals

1. Replace the current spreadsheets (cleaner timesheets and costings, client list) with one system of record.
2. Make clocking a job **a three-tap task** for cleaners on a phone: **START → STOP → SUBMIT**.
3. Never lose a timer: the start time is written to the database the moment START is tapped.
4. Turn approved timesheets into correct Xero invoices without re-keying.
5. Cut no-shows and late cancellations with automatic SMS reminders, and charge cancellation fees within 24 hours of an appointment with one click.
6. Give the owner a clear view of revenue, labour cost and margin per client, per cleaner and per period.

### Out of scope for v1

- Payroll processing and STP lodgement (labour cost is *reported*, not paid; Xero Payroll can come later).
- Client self-service portal and online booking.
- Native iOS or Android apps. The web app is an installable PWA.
- Offline job capture. A short-lived offline queue is in the v2 backlog (§10).

---

## 2. Brand summary

The full token set is in [05-UI-THEME.md](05-UI-THEME.md).

| Token | Hex | Use |
|---|---|---|
| `navy` (Midnight Navy) | `#161D2E` | Primary buttons, navigation, login header, logo on light surfaces |
| `navy-700` (Charcoal Navy) | `#242E42` | Hover and pressed state of navy buttons |
| `canvas` (Warm Alabaster) | `#F4F2EC` | Page background |
| `canvas-50` | `#FAF9F6` | Alternate canvas and table zebra rows |
| `surface` | `#FFFFFF` | Cards |
| `sand` (Warm Sand/Stone) | `#E5E1D8` | Card borders and dividers |
| `sage` (Muted Sage Olive) | `#8E9683` | Sparkle star, running-timer accent, active badges, focus ring |
| `sage-50` | `#E8EBE4` | Badge and highlight backgrounds |
| `offwhite` | `#F5F3EE` | Logo and text on navy |

**Logo:** bold geometric **APQ** (pointed "A" with no crossbar, four-point sage sparkle star at the top right of the "Q") above letter-spaced **CLEANING SERVICE**.

- Light surfaces: navy wordmark with a sage star.
- Navy surfaces: off-white or white wordmark with a sage star.

---

## 3. Users, roles and permissions

There are two roles, stored as `users.role` (`admin` | `staff`). Authorisation uses Laravel Policies and Gates, so no permission package is needed.

### 3.1 Cleaning staff (mobile portal)

| Capability | Rule |
|---|---|
| Sign in | Email + password. EN/ES pill on the login screen. "Remember me" is on by default. |
| Today's Jobs | Only the staff member's own assigned appointments for today, in time order. |
| Weekly Schedule | Their own assignments for the current week, with ◀ ▶ to move a week at a time (−4 to +8 weeks). |
| Start/Stop timer | Only on appointments they are assigned to, from 60 minutes before the scheduled start until the end of that day. One running timer per person at a time. |
| Submit timesheet | Add notes and submit. After submission the entry is locked for the staff member. Only an admin can change it. |
| My Profile | Can edit **name, avatar, email, password and language only**. Hourly rate, employment type, notes, active flag and role are hidden. They are also protected server-side (§3.3). |
| Client data shown | Client display name, address with a one-tap map link, access notes, and job notes for the visit. **Never** client billing rates, Xero data or phone numbers (unless the admin sets `show_phone_to_staff`). |

### 3.2 Administrator (desktop and mobile)

- Full CRUD on clients, staff, service rates, recurring schedules, one-off appointments, cancellation-fee rules, SMS templates and settings.
- Lock a staff account, which forces logout (§4.6).
- Review and adjust timesheets: logged minutes, billable minutes, notes, approve or reject.
- Generate Xero invoices individually or in batches, as `DRAFT` or `AUTHORISED`, optionally emailed by Xero.
- 24-hour cancellation monitor, including a one-click cancellation-fee invoice.
- SMS: automatic reminders, a one-click manual reminder, a free-text message, and the delivery log.
- Reports (§6).
- An admin can also be scheduled for jobs and use the staff screens (owner-operators clean too).

### 3.3 Security requirements

| Requirement | Implementation |
|---|---|
| Mass-assignment protection | `User::$fillable` excludes `role`, `is_active`, `hourly_rate`, `employment_type`, `notes`. Staff updates go through `UpdateOwnProfileRequest`, which `validated()`-whitelists `name, email, avatar, locale`. Password changes go through a separate request that needs `current_password`. Admin edits use `AdminUpdateStaffRequest` + `forceFill()`. |
| Horizontal access | `AppointmentPolicy`, `TimeEntryPolicy`: staff may only act on rows where they are an assignee or owner. Route-model binding is scoped to the policy. Out-of-scope IDs return 404, not 403. |
| Session revocation | `SESSION_DRIVER=database`. The lockout deletes `sessions` rows for the user, rotates `remember_token`, and `EnsureUserIsActive` middleware rechecks on every request. |
| Brute force | Login is throttled to 5 attempts per minute per email+IP (`RateLimiter`), with a localised message. |
| Passwords | Bcrypt (Laravel default) with `Password::min(10)->letters()->numbers()`. Admin-set temporary passwords force a change on first login (`must_change_password`). |
| Transport | HTTPS only (`APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`, HSTS via `.htaccess`). |
| Secrets | Xero tokens are stored with the `encrypted` cast. API credentials live in `.env` only. |
| Webhooks | Xero webhook HMAC signature verified. The SMS webhook is protected by a secret path token and an optional IP allow-list (§04). |
| Audit | `audit_logs` records lockouts, timesheet edits (before/after), invoice pushes, cancellations and SMS sends (§02). |
| Privacy | Australian Privacy Principles: client data is only shown to assigned staff, and avatars live on the private disk behind a signed route. |

---

## 4. Core workflows

### 4.1 Live job timer (staff)

```
Today's Jobs ──tap job──▶ Job card ──START JOB──▶ Running ──STOP JOB──▶ Review ──SUBMIT──▶ Submitted (locked)
                                   (POST /start)              (POST /stop)        (POST /submit)
```

1. **START JOB** (navy, full width, 56 px or taller)
   - `POST /jobs/{appointment}/start` creates a `time_entries` row with `started_at = now()` (server clock, UTC), `status = running`, and `running_lock = user_id`. The column has a unique index, so the database itself guarantees **one running timer per staff member**, even with double taps or two tabs.
   - The request is idempotent. If a running entry already exists for this staff member and appointment, it is returned unchanged.
   - Optional GPS: if the browser grants it, `start_lat/start_lng` are stored. The job is never blocked if the phone denies location.
   - The appointment moves to `in_progress`.
2. **Running card**
   - Large `HH:MM:SS` counter on a white card with a sage left border and a pulsing sage dot.
   - The counter is computed as `serverNow - started_at`. The server sends `started_at` and `server_now` and Alpine corrects for phone clock skew. Closing the browser, losing signal or the phone dying changes nothing: on reopen, the running entry loads from the database.
   - A sticky "Job running – 01:12:44" banner shows on every staff screen while a timer is running.
3. **STOP JOB** (high-contrast slate/charcoal with a stop icon, plus a confirm sheet: "Stop job for *Smith – Manly*?")
   - Sets `ended_at = now()`, `duration_seconds` and `duration_minutes = round(duration_seconds / 60)`, and `status = stopped`. Clears `running_lock`.
   - Pre-fills `billable_minutes` using the client's billing rule (§5.2).
4. **Review and Submit**
   - Shows Start `9:02 am`, End `11:47 am` and **165 min (2 h 45 m)** in large type.
   - One textarea, "Job notes / comments", with a localised placeholder ("e.g. Oven cleaned, out of glass cleaner").
   - **SUBMIT TIMESHEET** sets `status = submitted` and `submitted_at`. The entry is now read-only for staff.
   - "Oops, I forgot to stop": staff can tap **Adjust times** before submitting. This lets them pick the real start and end within the scheduled day, sets `staff_adjusted = true` and keeps the original timestamps in `original_started_at/original_ended_at` for the admin to see.
5. **Safety nets** (scheduled command `apq:timers:watchdog`, every 15 minutes)
   - A timer running longer than `scheduled duration + 3 h` (configurable) gets a push/SMS nudge to the cleaner and a flag on the admin dashboard.
   - A timer still running at 23:59 local time is auto-stopped at the scheduled end with `auto_stopped = true`, for the admin to review.

### 4.2 Weekly scheduling

- **Recurring schedules** (`recurring_schedules`): client, assigned staff (one or more), frequency (`weekly`, `fortnightly`, `every_3_weeks`, `every_4_weeks`, `monthly_nth_weekday`), weekday, start time, duration, effective from/to, service rate, default job notes.
- The **generator** (`apq:appointments:generate`, nightly at 01:00) creates concrete `appointments` 8 weeks ahead. It is idempotent: `(recurring_schedule_id, occurrence_date)` is unique. Editing a schedule regenerates future, untouched appointments only (`status = scheduled` and no time entries).
- **One-off appointments** are created directly with the same fields.
- **Schedule board (admin):** a week grid with staff as rows and days as columns. On desktop, appointment chips can be dragged to reassign or reschedule (Livewire + SortableJS). On mobile it is a day list.
- **Conflict warnings** (not hard blocks): a staff member double-booked with overlapping times, or a client with two visits on the same day.
- **Staff weekly view:** a day-by-day list. Each item shows client name, suburb, time window, a 📍 **Map** link (`https://maps.google.com/?q=<urlencoded address>`, which opens the native maps app) and a **START JOB** button on the day.

### 4.3 Appointment status model

```
scheduled ──start──▶ in_progress ──all assignees submitted──▶ completed ──invoiced──▶ invoiced
    │
    ├── cancel (≥ 24 h notice) ──▶ cancelled            (no fee)
    ├── cancel (< 24 h notice) ──▶ cancelled_late       (fee eligible → fee invoice optional / waived)
    └── no access / client not home ──▶ no_access       (treated as a late cancellation: fee eligible)
```

### 4.4 24-hour countdown and cancellation fees

- Each upcoming appointment shows a **countdown chip** based on `scheduled_start - now`:

| Time to start | Chip | Meaning |
|---|---|---|
| > 48 h | Neutral sand chip "in 3 d 4 h" | Free cancellation |
| 24–48 h | Sage chip "in 1 d 6 h" | Free cancellation, reminder window |
| < 24 h | Amber chip "⏱ 17 h 22 m – fee applies" | Inside the cancellation-fee window |
| Started / past | Navy chip "Due now" / "In progress" | — |

- **Cancellation monitor** (admin dashboard widget and `/admin/cancellations`): every appointment in the next 48 hours with its live countdown, client, staff, reminder SMS status and a **Cancel** action.
- **Cancel dialog**
  - Reason (client cancelled, client not home or no access, weather, staff unavailable, other).
  - `notice_given_at` defaults to now. The admin can backdate it to when the client actually called.
  - If the notice is inside the window and the reason is client-caused, the system suggests **"Charge cancellation fee: $X"**, pre-calculated from the client's fee rule (§5.3). The admin chooses **Create fee invoice** (Draft or Authorised + email) or **Waive** (reason required, which is audited).
  - Staff-caused reasons never suggest a fee.
- The fee invoice is pushed to Xero as its own invoice, reference `CXL-<appointment id>` (§03).
- The assigned cleaners get an SMS ("Job cancelled: Smith – Manly, Tue 9:00 am") if `notify_staff_on_cancel` is on, and the visit disappears from their list.

### 4.5 Timesheet review and adjustment (admin)

- **Grid** at `/admin/timesheets?from=&to=&staff=&client=&status=`, defaulting to the current pay period (weekly, fortnightly or monthly, set in settings).
  - Columns: date, client, staff, scheduled minutes, **logged minutes**, **billable minutes** (editable), variance badge, notes (editable), status, flags (auto-stopped, staff-adjusted, GPS far from address).
  - Inline editing with Livewire. Every change writes an audit row (before/after, admin, reason).
  - Bulk actions: **Approve**, **Reject → back to staff** (with a comment), **Create invoices**.
- Status flow: `running → stopped → submitted → approved → invoiced` (plus `rejected → stopped`). Only `approved` entries can be invoiced. Invoiced entries are locked. Unlocking needs the Xero invoice voided or credited first (§03).
- **Missing timesheets:** past appointments with no submitted entry are listed at the top in amber.

### 4.6 Account lockout and force logout

`POST /admin/staff/{user}/lock` (also a toggle on the staff profile) runs:

1. `is_active = false`, `locked_at`, `locked_by`, `lock_reason`.
2. `DB::table('sessions')->where('user_id', $user->id)->delete();` This takes effect immediately. The next request from that phone has no session.
3. `remember_token` is rotated, which kills "remember me" cookies.
4. Any running timer is auto-stopped (`auto_stopped = true`, `stopped_by_admin = true`).
5. Future assignments are listed for the admin to reassign. They are not deleted automatically.
6. Audit log entry.

The login attempt checks `is_active` after the credentials are verified. A locked user sees the localised message `auth.locked`: *"Your account has been disabled. Please contact APQ Cleaning Service."* / *"Tu cuenta ha sido desactivada. Por favor contacta a APQ Cleaning Service."* The `EnsureUserIsActive` middleware also logs out any session that slips through. **Unlock** reverses steps 1 and 3 only.

### 4.7 SMS reminders

Details are in [04-SMS-MOBILE-MESSAGE.md](04-SMS-MOBILE-MESSAGE.md).

- Automatic reminder at a configurable offset. The default is **the day before at 4:00 pm** for all next-day appointments. Alternatively, *N* hours before.
- Sends only inside quiet hours (08:00–20:00 Sydney) and only to clients with `sms_opt_in = true`.
- One-click **Send reminder now** on any appointment, plus a free-text SMS to a client.
- The delivery status of every message is stored and shown (queued → sent → delivered / failed).

### 4.8 Xero invoicing

Details are in [03-XERO-INTEGRATION.md](03-XERO-INTEGRATION.md).

- The admin connects Xero once (OAuth 2.0). Clients are linked to Xero contacts, matched by email or name or created automatically.
- **Invoice runs:** pick a period and clients (or "all with approved time"). Preview shows one invoice per client, one line per visit (`"Tue 4 Nov – Regular clean (2.75 h)"`), quantity in hours, unit price = client rate, GST on income. Then **Create in Xero** as **Draft** or **Authorised**, with an optional *Email to client via Xero*.
- Runs are queued jobs. Each invoice is pushed with an idempotency key so a retry never duplicates.
- Invoice number, Xero `InvoiceID`, status, total and online URL are stored. A Xero webhook (or an hourly poll) updates `PAID` status.

### 4.9 Language switching

- An **EN / ES** pill sits in the top header on every page, including login.
- Before login, the choice is saved in a `locale` cookie (1 year). After login it is saved in `users.locale`. The `SetLocale` middleware uses: user → session → cookie → `Accept-Language` → `en`.
- All UI strings, validation messages, emails and staff SMS use `lang/en/*.php`, `lang/es/*.php` and `lang/{en,es}.json`. Client SMS use `clients.preferred_language`.
- Dates are localised with Carbon `locale()` (`martes 4 de noviembre`).

---

## 5. Pricing and calculation rules

### 5.1 Rates

| Entity | Fields | Notes |
|---|---|---|
| `service_types` | name (en/es), default hourly rate, default fixed price, Xero item code, Xero account code | e.g. *Regular clean*, *Deep clean*, *End of lease*, *Oven*, *Windows*. |
| `clients` | `pricing_model` (`hourly` / `fixed`), `hourly_rate`, `fixed_price`, `billing_increment_minutes`, `minimum_billable_minutes`, cancellation fee rule | The client's value overrides the service type default. |
| `users` (staff) | `hourly_rate`, `employment_type` (`casual`, `part_time`, `full_time`, `contractor_abn`) | Used for labour cost and margin reporting. |
| Rate history | `client_rate_histories`, `staff_rate_histories` (effective_from) | Invoices and reports use the rate in force **on the visit date**. Each time entry also snapshots `charge_rate` and `pay_rate` at approval. |

### 5.2 Billable minutes

```
logged      = round(duration_seconds / 60)
rounded     = ceil(logged / increment) * increment      // increment: client.billing_increment_minutes (default 15; 1 = exact)
billable    = max(rounded, client.minimum_billable_minutes)   // default 0
amount_ex   = pricing_model == 'fixed' ? fixed_price : billable / 60 * hourly_rate
gst         = round(amount_ex * 0.10, 2)
```

- With **several cleaners on one visit**, each entry's billable minutes are summed, so 2 cleaners × 2 h = 4 h charged, unless the client is on `fixed` pricing (one fixed line per visit).
- The admin can override `billable_minutes` on any entry, with the reason audited.
- Labour cost = `duration_minutes / 60 * pay_rate`, plus on-cost % from settings (default 12 % superannuation guarantee for employees, 0 % for ABN contractors; configurable).

### 5.3 Cancellation fee rule (per client, defaulting from settings)

| Field | Default |
|---|---|
| `cancellation_window_hours` | 24 |
| `cancellation_fee_type` | `percent_of_visit` (or `fixed`) |
| `cancellation_fee_value` | 50 % of the scheduled visit value (scheduled minutes × rate, or the fixed price); or a fixed amount, e.g. $60 |
| `cancellation_fee_cap` | none |

`fee_ex_gst = type == fixed ? value : round(visit_value * value / 100, 2)`.

The fee is a separate line item with its own Xero account code (default `260 Other Revenue`, configurable).

---

## 6. Reporting

All reports filter by date range, staff, client and suburb, and export to CSV and print-friendly PDF (`barryvdh/laravel-dompdf`).

| Report | Contents |
|---|---|
| **Dashboard** (admin home) | Today's jobs and live timers, missing timesheets, next 48 h cancellation monitor, approved-but-uninvoiced $, SMS failures, Xero connection health |
| **Revenue by client** | Visits, billable hours, revenue ex GST, GST, inc GST, cancellation fees, average $/visit |
| **Staff hours and labour cost** (payroll export) | Per staff per day: logged minutes, approved minutes, pay rate, gross, on-costs. CSV export in Xero Payroll timesheet import format. |
| **Margin** | Revenue − labour cost per client, per staff and per week. Effective hourly yield. |
| **Scheduled vs actual** | Variance per visit and per client (spots under-quoted clients) |
| **Cancellations** | Late cancellations, fees charged vs waived, by client and reason |
| **SMS log** | Sent, delivered, failed, opt-outs, credit usage per month |
| **Xero sync** | Invoices created, failed pushes with error text, unlinked clients, payment status, outstanding $ |
| **Audit log** | Filterable list of sensitive actions |

---

## 7. Data migration from the existing spreadsheets

Source files (supplied 13 Nov 2026; kept out of git and placed in `storage/import/`):

| File | What it contains |
|---|---|
| **Weekly Planner** (`.xlsx`, one sheet per week from May 2025) | Per day: an **EMPLEADOS** block (employee, hours, minutes, total minutes, hourly rate, per-minute rate, total paid) and a **CLIENTES** block (client first name, service hours, service value). Weekly totals, staff payments and "weekly profit". Also an *Informe* per-employee summary sheet. |
| **Client database** (`Datos` sheet, CSV) | 198 clients: customer, phone, email, address, payment type (Cash/ABN), frequency, "focus on" special instructions, service type, service address, state, method of entry (key/lockbox codes), referral source, gifts. |

### Findings that shape the design

- **Clients are charged a fixed price per visit** (e.g. $350 for ~7 h), not hourly. Default `clients.pricing_model = fixed`. The hourly option remains for new clients.
- Service hours are written as **h.mm** (`7.15` = 7 h 15 m, `6.45` = 6 h 45 m). The importer converts these to minutes.
- **Staff pay is hourly per person** ($30–$50/h), with whole-day hours + minutes. Older sheets show higher Saturday rates. Phase 2 adds optional **weekday/weekend pay rates** (`staff_rate_histories.day_type`).
- The planner does **not** record which cleaner attended which client. From go-live, the app captures this per visit (`time_entries`).
- *Payment type* (Cash / ABN) is stored per client (`clients.payment_method`). Cash clients may not need Xero invoices: this is an **open question** (§10).
- *Frequency* values: Weekly, Biweekly, Every 3 weeks / Cada 3 semanas, Monthly / Mensual / Cada 4 semanas, Twice a week, Sporadic, AIRBNB. These map to `recurring_schedules.frequency`; Sporadic and Airbnb become one-off appointments.
- *Method of entry* (codes, lockbox, under the mat) → `clients.access_notes`, which is shown only to assigned staff. *Focus on* → `clients.default_job_notes`.
- The *State* column reads "Cancelled" on almost every row of the export. The importer has `--status=keep|active` and reports it in the dry run, so it isn't silently applied.
- Columns P–S of the CSV are dropdown lookup lists, not client data, and are ignored.

### Importers

- `php artisan apq:import:staff <planner.xlsx> [--sheet=] [--commit]` (**built in Phase 0**). It reads names and hourly rates from the latest weekly sheet and creates staff with placeholder emails and temporary passwords. Existing accounts are matched by name and only their rate is updated.
- `php artisan apq:import:clients <datos.csv> [--commit]` (Phase 1). Australian phone normalisation (`478651324` → `+61478651324`, international numbers kept), duplicate detection by phone/email/name, and a dry-run report.
- `php artisan apq:import:history <planner.xlsx>` (Phase 2, optional). Daily staff hours and client revenue become legacy entries for margin reporting.

## 8. Non-functional requirements

| Area | Target |
|---|---|
| Performance | Staff screens < 1.5 s on 4G (no SPA bundle, < 60 KB JS gzipped). Admin grids paginate at 50 rows. |
| Availability | Shared or VPS cPanel hosting. Queue via cron-driven `queue:work --stop-when-empty` (see 06 §7). |
| Accessibility | WCAG 2.2 AA: contrast (navy on alabaster 15.0:1, off-white on navy 15.2:1), 56 px touch targets, `prefers-reduced-motion` honoured for the timer pulse. |
| Browser support | Latest 2 versions of iOS Safari, Android Chrome, desktop Chrome, Edge, Firefox and Safari |
| Backups | Nightly `mysqldump` (cPanel backup or `spatie/laravel-backup`) with 30-day retention, off-site |
| Logging | `daily` log channel (14 days). Integration errors also go to `integration_logs` (§02). |
| Data retention | Time entries and invoices kept 7 years (ATO). SMS bodies purged after 2 years. |

---

## 9. Acceptance criteria (summary)

1. A cleaner on an iPhone can sign in in Spanish, start a job, close Safari, reopen 30 minutes later and see the timer still running at the correct elapsed time.
2. Double-tapping START creates exactly one time entry. Starting a second job while one is running shows "Stop your current job first".
3. A staff `PATCH /profile` with `hourly_rate=99&role=admin&is_active=1` changes nothing except the allowed fields.
4. Locking a staff member while they are logged in on a phone logs them out on their next tap. Their login then shows the localised lockout message.
5. Approving 10 entries for 3 clients and running "Create invoices (Draft)" makes exactly 3 Xero draft invoices with correct GST. Re-running the batch creates no duplicates.
6. Cancelling an appointment 10 h before start suggests the client's fee. Choosing "Authorised + email" produces a Xero invoice emailed to the client.
7. The day-before reminder is sent once per appointment, not to opted-out clients, not outside 08:00–20:00, and its delivery status appears in the SMS log.
8. Every UI string on the staff screens and login has a Spanish translation (`php artisan apq:lang:check` finds no missing keys).

---

## 10. Backlog (post-v1)

- Offline queue for START/STOP using a service worker + IndexedDB, replayed with the original device timestamps flagged for review.
- Web Push notifications to staff (new or changed jobs).
- Client portal: view upcoming visits, cancel online (auto-fee rules).
- Xero Payroll AU timesheet push.
- Photo checklist uploads per job.
- Inbound SMS replies ("C" to confirm, "R" to reschedule) parsed into appointment confirmations.
