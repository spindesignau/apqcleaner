# What's new in APQ Cleaning Service

Changes are listed newest first. This page is visible to administrators only.

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
