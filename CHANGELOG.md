# What's new in APQ Cleaning Service

Changes are listed newest first. This page is visible to administrators only.

## [0.8.1] – 23 November 2026

### Moving to apqcleaner.com.au
- **Copy staging to the live site** in one step (GitHub → Actions → copy-staging-to-production). Clients, cleaners, logins, schedules, timesheets and invoices all come across. The live site's previous data is backed up first.
- **The main domain works without root access:** the live deploy links `public_html` to the app, and keeps cPanel's PHP version setting through every deploy.
- **The every-minute scheduled task is now set up automatically** on each deploy, so invoices don't get stuck on "Sending…" and reminders and backups run without extra cPanel setup.
- **Staging can no longer text real clients.** Without a test phone number set, staging records messages as "not sent" instead of sending them. After the copy, staging is also disconnected from Xero, so it can't create real invoices.

## [0.8.0] – 18 November 2026

### New — clients sync with Xero both ways
- **Import clients from Xero** (Xero page or Clients page). Every Xero contact is shown as *New*, *Matches an APQ client* or *Already linked*. Tick the ones you want: new ones become clients, matches get linked. Suppliers are hidden unless you choose "All contacts".
- **Changes in APQ go to Xero.** Edit a linked client's name, email, phone or address and Xero is updated straight away.
- **Changes in Xero come back.** They arrive instantly if the Xero webhook includes Contacts, and otherwise within the hour. There's also a **Sync now** button.
- Only contact details sync. Prices, notes, access codes and schedules stay in APQ, and a blank field in Xero never erases anything in APQ.
- Each client's page shows a **Xero ✓** badge when linked, or the reason when a sync fails.

### Changed
- **Create invoices** now opens on the 1st of this month to today, instead of last month. You can still change the dates.
- **Send invoices automatically:** Xero → Invoice settings has one simple choice: *Draft*, *Approved*, or *Approved and emailed to the client by Xero*. With the last one, every invoice run is approved and emailed as soon as you press Create. You're asked to confirm first, and you can still switch it off for a single run.

## [0.7.2] – 18 November 2026

### Fixed
- **Xero account check** no longer reports a bank account (such as "Smart Access") as your sales account. Built-in settings like the Sales (200) and Other Revenue (260) account codes, SMS reminder times and timer limits now correctly fall back to their defaults until you change them.
- **SMS account check** no longer warns about Mobile Message's shared or dedicated numbers, which their API doesn't list. It now suggests sending a test SMS instead, and matches numbers however they're written (0466…, +61466…, 61466…).

## [0.7.1] – 14 November 2026

### Improved — smoother everywhere
- **Pages** fade and slide in. The top bar, admin menu and cleaners' tab bar stay still, so it feels like one app rather than separate pages.
- **A thin loading bar** runs across the top while the next page loads (handy on slow mobile data).
- **Buttons** press in slightly when tapped, lift on hover, and show a spinner while saving, so nobody taps twice.
- **Confirm windows** (stop job, sign out, lock account, void invoice, end schedule, delete visit, disconnect Xero) are now APQ-styled pop-ups that slide up on phones. They replace the browser's plain grey boxes.
- **Show/hide sections** (adjust times, pricing fields, fee options, email options) open and close smoothly. Expanding invoice lines slide open.
- **Messages:** confirmation toasts slide in, error messages fade in, and an invalid field gives a gentle shake.
- **Lists and cards** (today's jobs, the week, the schedule board, reports) appear with a quick cascade. Dashboard numbers count up.
- **On the cleaners' phones,** the running timer pops in when a job starts, the active tab's indicator grows in, and phones that support it give a short buzz on START, STOP and SUBMIT.
- **An offline banner** tells cleaners when they've lost signal (the timer keeps counting).
- **Reduce motion:** anyone who has asked their phone or computer to reduce motion gets everything instantly, with no animation.

## [0.7.0] – 14 November 2026

### New
- **Reports** (menu → Reports). Each one has date presets (pay period, month, financial year), a CSV export and Print / PDF:
  - **Revenue by client:** visits, billable hours, cleaning revenue and cancellation fees, with GST.
  - **Staff hours & pay:** weekday, Saturday and Sunday hours per cleaner, pay at the locked-in rates, and on-costs. Tick *One line per shift* for a payroll export.
  - **Margin by client:** revenue minus labour, lowest first, to spot under-priced clients.
  - **Scheduled vs actual time:** which clients' visits keep running over.
  - **Cancellations, SMS usage, and Invoices & Xero.**
- **Settings page** with **System status**: warns if the cPanel cron job stops, which would stop visits, reminders, invoices and backups. The dashboard shows a red banner if that happens.
- **Nightly database backups** (kept 30 days), with **Back up now** and **Download** under Settings → Backups.
- **Live-site deploys** are prepared. They only run when started by hand, never automatically.

### Improved
- Tested on MariaDB (the database the hosting uses) as well as for development.

## [0.6.1] – 14 November 2026

### Improved
- **SMS checked against Mobile Message's official API documentation:**
  - Numbers are sent in their required format.
  - Retries can never double-send.
  - "Out of credits" and "unsubscribed" are shown clearly.
  - A busy moment at Mobile Message no longer loses an automatic reminder: it is tried again 5 minutes later.
- **Check Mobile Message account** button on the SMS page: shows your credit balance and warns if the sender name isn't approved yet.
- Reminders use Mobile Message's official opt-out wording, and clients who unsubscribe through their link are opted out here too.
- Optional webhook signing (MOBILEMESSAGE_WEBHOOK_SECRET) for extra security.

## [0.6.0] – 14 November 2026

### New
- **Cancellations** (menu → Cancellations):
  - Every visit in the next 48 hours shows a countdown. It turns amber with "fee applies" inside the client's cancellation window, usually 24 hours.
  - **Cancel visit** records the reason and when the client told you, then suggests the fee. It can charge it as its own Xero invoice (Draft or Approved, optionally emailed) or waive it with a reason. Cleaner-caused and weather cancellations never carry a fee.
  - The client and the assigned cleaners can be sent an SMS automatically.
- **Cancellation fee rule per client:** a % of the visit price (default 50%), a fixed amount, or no fee, plus the notice window in hours.
- **SMS reminders** (menu → SMS):
  - Clients get a reminder the day before each visit, from 4pm by default, and only between 8am and 8pm.
  - **Send reminder** sends one immediately, and you can send a free-text SMS from a client's page.
  - Message templates can be edited in English and Spanish.
  - Every message is listed with its delivery status. A client who replies STOP is opted out automatically.
- **What's new** page (this page), linked at the bottom left of the admin menu. Administrators only.

### Note
- SMS stays in **test mode** until the Mobile Message account details are added to the server: messages are listed in the SMS log but not sent.

## [0.5.0] – 14 November 2026

### New
- **Xero invoicing.** Connect Xero under Settings, then use **Invoices → Create invoices** to bill approved timesheets for a period:
  - The preview shows every client before anything is created.
  - Invoices go to Xero as Draft or Approved (optionally emailed to the client), and their paid status comes back automatically.
- **Upload a cleaner's schedule.** On the Schedule page, download the template, fill it in Excel or Google Sheets, upload it and check the preview. A blank Repeat column makes a one-off visit; "Weekly", "Fortnightly" and so on create a regular schedule.
- **Invoice frequency per client** (per visit, weekly, fortnightly or monthly).
- **Sign-out button** at the top of the cleaners' phone screens.

### Improved
- The phone sign-in page has a smaller logo, so the whole form fits on screen.
- The dashboard shows timesheets waiting to be invoiced, failed invoices, and a warning if Xero is not connected.

## [0.4.0] – 13 November 2026

### New
- **Staging site** at apqcleaner.spinstaging.com, updated automatically from GitHub.
- **Import clients from a spreadsheet** (Clients → Import from spreadsheet), with a preview, duplicate detection and warnings.

### Changed
- **English is the default language** for everyone. Spanish is chosen with the EN/ES switch and remembered.

## [0.3.0] – 13 November 2026

### New
- **Job timer for cleaners:** START → STOP → SUBMIT on their phone. Closing the browser or losing signal never loses the time.
- **Timesheet review:** filter by pay period, approve in bulk, edit times with a reason, send back to the cleaner, or add time for a forgotten visit.
- Timers left running overnight stop themselves. Saturday and Sunday pay rates are supported.

## [0.2.0] – 13 November 2026

### New
- **Clients:** price per visit, how to get in and "focus on" notes. Cleaners only see these for their own visits.
- **Regular schedules** (weekly, fortnightly, every 3 or 4 weeks, monthly) that create visits 8 weeks ahead.
- **Week board** with double-booking warnings, and Today/Week screens for cleaners.

## [0.1.0] – 13 November 2026

### New
- Sign-in in English and Spanish, staff accounts, instant account lock, and the APQ look and feel.
