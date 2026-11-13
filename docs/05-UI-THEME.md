# APQ Cleaning Service — UI Theme, Brand Assets and Screens

**Stack:** Blade + Livewire 3 + Alpine.js (bundled with Livewire) + **Tailwind CSS v4** (Vite, Laravel 12 default).

Fonts are **Montserrat** (headings, logo tagline, buttons) and **Inter** (body), self-hosted from `resources/fonts` (no Google Fonts request, which helps both privacy and 4G speed).

## 1. Design tokens — `resources/css/app.css`

```css
@import "tailwindcss";
@plugin "@tailwindcss/forms";

@theme {
  /* Brand */
  --color-navy:        #161D2E;   /* Midnight Navy – primary, nav, logo on light */
  --color-navy-700:    #242E42;   /* Charcoal Navy – hover/pressed */
  --color-navy-500:    #3A4560;   /* muted text on light, disabled navy */
  --color-canvas:      #F4F2EC;   /* Warm Alabaster – page */
  --color-canvas-50:   #FAF9F6;   /* Stone Off-White – zebra rows, inputs */
  --color-surface:     #FFFFFF;   /* cards */
  --color-sand:        #E5E1D8;   /* borders, dividers */
  --color-sand-600:    #C9C3B6;   /* stronger borders, input outline */
  --color-sage:        #8E9683;   /* sparkle star, active timer, badges, focus ring */
  --color-sage-700:    #5E6656;   /* sage text on white/sage-50 (AA 4.97–5.98:1) */
  --color-sage-50:     #E8EBE4;   /* badge / highlight bg */
  --color-offwhite:    #F5F3EE;   /* logo + text on navy */

  /* Functional */
  --color-stop:        #3B3F46;   /* STOP JOB – high-contrast slate */
  --color-stop-hover:  #24272C;
  --color-alert:       #B4462F;   /* destructive / failed */
  --color-alert-50:    #F6E4DF;
  --color-amber:       #8A540C;   /* <24h countdown, warnings (5.35:1 on amber-50) */
  --color-amber-50:    #F7ECD8;
  --color-success:     #4F6B47;   /* delivered/approved (sage-family green) */

  --font-sans:    "Inter", ui-sans-serif, system-ui, sans-serif;
  --font-display: "Montserrat", "Inter", ui-sans-serif, sans-serif;

  --radius-card: 1rem;
  --radius-btn:  0.875rem;
  --shadow-card: 0 1px 2px rgb(22 29 46 / .04), 0 4px 16px rgb(22 29 46 / .06);
  --spacing-touch: 3.5rem;        /* 56px min touch height */
}

@layer base {
  html { background: var(--color-canvas); color: var(--color-navy); -webkit-tap-highlight-color: transparent; }
  body { @apply font-sans text-[17px] leading-relaxed antialiased; }
  h1,h2,h3 { @apply font-display font-semibold tracking-tight; }
  :focus-visible { outline: 3px solid var(--color-sage); outline-offset: 2px; }
}

@layer components {
  .card        { @apply bg-surface border border-sand rounded-[var(--radius-card)] shadow-[var(--shadow-card)] p-5; }
  .btn         { @apply inline-flex w-full items-center justify-center gap-2 min-h-[var(--spacing-touch)]
                        rounded-[var(--radius-btn)] px-6 font-display text-lg font-semibold tracking-wide uppercase
                        transition-colors disabled:opacity-50 disabled:cursor-not-allowed; }
  .btn-primary { @apply btn bg-navy text-offwhite hover:bg-navy-700 active:bg-navy-700; }
  .btn-stop    { @apply btn bg-stop text-white hover:bg-stop-hover ring-2 ring-offset-2 ring-offset-surface ring-stop; }
  .btn-secondary { @apply btn bg-surface text-navy border-2 border-navy hover:bg-canvas-50; }
  .btn-sage    { @apply btn bg-sage text-white hover:bg-sage-700; }
  .input       { @apply block w-full min-h-[var(--spacing-touch)] rounded-xl border-sand-600 bg-canvas-50 px-4 text-lg
                        focus:border-sage focus:ring-sage; }
  .label       { @apply mb-1.5 block text-sm font-semibold text-navy-500 uppercase tracking-wider; }
  .badge       { @apply inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold; }
  .badge-sage  { @apply badge bg-sage-50 text-sage-700; }
  .badge-amber { @apply badge bg-amber-50 text-amber; }
  .badge-navy  { @apply badge bg-navy text-offwhite; }
  .badge-sand  { @apply badge bg-canvas text-navy-500 border border-sand; }
  .lang-pill   { @apply inline-flex rounded-full border border-current/30 p-0.5 text-sm font-semibold; }
  .lang-pill a { @apply rounded-full px-3 py-1.5 min-w-11 text-center; }
  .lang-pill a[aria-current="true"] { @apply bg-sage text-white; }
}

@media (prefers-reduced-motion: reduce) { .animate-pulse { animation: none; } }
```

### Contrast check (WCAG 2.2)

| Pair | Ratio | Use |
|---|---|---|
| navy `#161D2E` on canvas `#F4F2EC` | 15.0 : 1 | body text ✓ AAA |
| offwhite `#F5F3EE` on navy | 15.2 : 1 | buttons and header ✓ AAA |
| white on stop `#3B3F46` | 10.6 : 1 | STOP JOB ✓ AAA |
| sage `#8E9683` on white | 3.1 : 1 | **decorative, icons, borders and focus ring only** — never text |
| sage-700 `#5E6656` on sage-50 `#E8EBE4` | 5.0 : 1 | badge text ✓ AA |
| amber `#8A540C` on amber-50 `#F7ECD8` | 5.4 : 1 | countdown chip ✓ AA |
| white on sage `#8E9683` | 3.1 : 1 | `.btn-sage` only at 18 px bold uppercase (AA large text). Prefer `bg-sage-700` (6.0 : 1) for any smaller text. |

## 2. Logo usage

Placeholder assets are in [`/brand`](../brand). **Replace these with the designer's master vector files before launch.** The SVGs are a geometric redraw for development and keep the same file names.

| File | Use |
|---|---|
| `apq-logo-light.svg` | Navy wordmark with sage star, for light surfaces (admin sidebar on canvas, PDFs, emails) |
| `apq-logo-dark.svg` | Off-white wordmark with sage star on navy (login header, staff top bar) |
| `apq-emblem.svg` | Square white-on-navy emblem; source for app icons |
| `icon-192.png`, `icon-512.png` | PWA manifest icons (also `purpose: maskable`, with the mark inside the safe zone) |
| `apple-touch-icon.png` (180×180) | iOS home screen |
| `favicon.ico` (32+48), `favicon-32.png` | Browser tab |

Blade component `<x-logo variant="dark|light" class="h-12" />` inlines the SVG (no extra request; the star colour comes from the token). Keep clear space of at least the height of the "Q" counter around the logo. The minimum width is 96 px with the tagline, or 56 px without (`<x-logo :tagline="false" />`) for the compact staff top bar.

### `public/manifest.webmanifest`

```json
{
  "name": "APQ Cleaning Service",
  "short_name": "APQ",
  "start_url": "/jobs/today",
  "scope": "/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#161D2E",
  "theme_color": "#161D2E",
  "icons": [
    { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any maskable" },
    { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable" }
  ]
}
```

`<head>` tags:

```html
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#161D2E">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
```

## 3. Layouts

| Layout | Who | Structure |
|---|---|---|
| `layouts/guest.blade.php` | Login, password reset | Navy header panel (logo + EN/ES pill), white card on alabaster |
| `layouts/staff.blade.php` | Staff (and admins using staff views) | Compact navy top bar: small logo, EN/ES pill, running-timer banner. Content max-w-md. **Bottom tab bar with 3 items** (Today · Week · Profile), 64 px, safe-area padding. |
| `layouts/admin.blade.php` | Admin | Desktop: navy left sidebar (Dashboard, Schedule, Timesheets, Clients, Staff, Invoices, Cancellations, SMS, Reports, Settings). Mobile: top bar + slide-over menu, plus a "Staff view" switch. |

## 4. Staff screens (wireframes)

### 4.1 Login

```
┌──────────────────────────────┐
│▓▓▓▓▓▓▓▓▓▓ NAVY ▓▓▓▓▓▓▓▓▓▓▓▓▓▓│
│▓                  [EN | ES] ▓│
│▓          APQ ✦             ▓│
│▓    C L E A N I N G  S E R V I C E │
│▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓│
│  ┌────────────────────────┐  │  ← white card, -mt-10 overlapping header
│  │ Welcome back           │  │
│  │ EMAIL                  │  │
│  │ [____________________] │  │  56px inputs, inputmode=email
│  │ PASSWORD               │  │
│  │ [________________ 👁 ] │  │  show/hide toggle
│  │ ☑ Keep me signed in    │  │
│  │ [██████ SIGN IN ██████]│  │  .btn-primary
│  │   Forgot password?     │  │
│  └────────────────────────┘  │
│        alabaster canvas      │
└──────────────────────────────┘
```

### 4.2 Today's Jobs

```
[navy bar: APQ✦     EN|ES]
 Tuesday 4 November                     ← H1, localised
 3 jobs today

┌ card ───────────────────────────┐
│ 9:00 – 11:30am     ⏱ badge-sage │
│ Smith – Manly                    │  ← text-xl bold
│ 📍 12 Ocean Rd, Manly  [Map]     │
│ 🔑 Key in lockbox 4471           │  ← access notes (2 lines max, tap to expand)
│ [████████ START JOB ███████]     │
└──────────────────────────────────┘
┌ card (sage left border 6px) ─────┐  ← RUNNING
│ ● IN PROGRESS                    │  ← pulsing sage dot
│ Jones – Dee Why                  │
│        01:12:44                  │  ← font-display 56px tabular-nums
│ Started 9:02am                   │
│ [████████ STOP JOB ████████]     │  .btn-stop
└──────────────────────────────────┘
┌ card (muted) ────────────────────┐
│ ✓ Submitted · 2h 45m             │
│ Brown – Mona Vale                │
└──────────────────────────────────┘
[ Today | Week | Profile ]  ← bottom tabs
```

### 4.3 Review and Submit (after STOP)

```
 Jones – Dee Why
┌──────────┬──────────┐
│ START    │ END      │
│ 9:02am   │ 11:47am  │   ← text-3xl
└──────────┴──────────┘
   TOTAL  165 min           ← text-5xl, sage underline
        (2 h 45 m)
 Adjust times ›             ← secondary link
 JOB NOTES / COMMENTS
 [ textarea, 4 rows,      ]
 [ "e.g. Oven cleaned…"   ]
 [██████ SUBMIT TIMESHEET ██████]
```

### 4.4 Weekly Schedule

The week heading has ◀ ▶ buttons and a "This week" chip. Days are sticky section headers ("Mon 3 Nov"). Each job row shows the time, client, suburb and status badge. Tapping a row opens the job card. Today is highlighted with a sage left rule.

### 4.5 My Profile

Avatar (tap to change, cropped square, max 2 MB, resized to 512 px), Name, Email, **Change password** (current + new + confirm), Language (EN/ES segmented control), **Sign out**. There are no other fields.

## 5. Timer component (Alpine)

```blade
{{-- resources/views/components/job-timer.blade.php --}}
@props(['entry'])
<div x-data="jobTimer('{{ $entry->started_at->toIso8601String() }}', '{{ now()->toIso8601String() }}')"
     x-init="start()" class="text-center">
  <div class="font-display text-[56px] leading-none font-semibold tabular-nums" x-text="display" aria-live="off"></div>
  <span class="sr-only" x-text="srText" aria-live="polite"></span>
</div>
```

```js
// resources/js/job-timer.js
export default (startedAtIso, serverNowIso) => ({
  skew: Date.parse(serverNowIso) - Date.now(),   // correct for phone clock drift
  started: Date.parse(startedAtIso),
  display: '00:00:00', srText: '',
  start() {
    const tick = () => {
      const s = Math.max(0, Math.floor((Date.now() + this.skew - this.started) / 1000));
      const p = n => String(n).padStart(2, '0');
      this.display = `${p(Math.floor(s / 3600))}:${p(Math.floor(s % 3600 / 60))}:${p(s % 60)}`;
      if (s % 60 === 0) this.srText = `${Math.floor(s / 60)} min`;
    };
    tick(); setInterval(tick, 1000);
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && tick());
  },
});
```

START, STOP and SUBMIT are plain `<form method="POST">` posts (Livewire actions are optional), so they work on flaky connections and never depend on JS state. Each button gets `x-on:submit` → `disabled + spinner` to stop double taps. The server is idempotent anyway.

## 6. Language switcher

```blade
<nav class="lang-pill" aria-label="{{ __('Language') }}">
  @foreach (['en' => 'EN', 'es' => 'ES'] as $code => $label)
    <a href="{{ route('locale.switch', $code) }}" hreflang="{{ $code }}"
       aria-current="{{ app()->getLocale() === $code ? 'true' : 'false' }}">{{ $label }}</a>
  @endforeach
</nav>
```

`GET /locale/{locale}` (whitelisted `en|es`) sets the cookie (1 year) and session, updates `users.locale` if authenticated, and then `back()`.

## 7. Admin UI notes

- Tables: white card, sand dividers, canvas-50 zebra, sticky header, and a mobile card layout below `md`.
- Status badges: `scheduled` sand · `in_progress` sage (pulse) · `submitted` navy outline · `approved` success · `invoiced` navy · `cancelled_late` amber · `failed` alert.
- Countdown chip colours follow spec §4.4.
- Toasts: bottom-centre on mobile, top-right on desktop. Navy background with a sage check icon.
- Empty states: a sage sparkle-star illustration plus one primary action.
