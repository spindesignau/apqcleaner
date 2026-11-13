# APQ Cleaning Service — Mobile Message SMS Integration

| | |
|---|---|
| **Provider** | Mobile Message — https://mobilemessage.com.au/ (Australian SMS gateway) |
| **Used for** | Client appointment reminders (automatic and manual), cancellation notices, free-text SMS to clients, and job-cancelled notices to staff |
| **Transport** | REST/JSON over HTTPS via Laravel's `Http` client. No SDK needed. |

> ### ⚠ Verify before build
> The Mobile Message API documentation could not be fetched while this spec was written: the site is outside this environment's network allow-list.
>
> The endpoint, field and webhook names below come from the provider's commonly documented v1 API, **but they have not been checked against the live docs**. Before coding, confirm them in the Mobile Message dashboard → *Settings → API* / API documentation.
>
> All provider-specific names are isolated in **one class** (`MobileMessageClient`) and `config/mobilemessage.php`, so any differences are a single-file change.

## 1. Account set-up checklist

1. Create API credentials in the Mobile Message dashboard (API username + API password/key).
2. Choose the **sender**:
   - **Alphanumeric sender ID** `APQClean` (max 11 characters). It looks professional, **but clients cannot reply**.
   - **Dedicated virtual mobile number**. Clients can reply (needed for STOP opt-outs by reply and for v2 "C to confirm").
   - **Recommendation:** a dedicated number, because two-way replies make opt-out compliance automatic.
3. Australia now runs an **SMS Sender ID Register** (ACMA). Register any alphanumeric sender ID through Mobile Message so messages aren't blocked or flagged as possible scams.
4. Configure webhooks in the dashboard:
   - Delivery receipts → `https://<app>/webhooks/mobile-message/{token}/status`
   - Inbound messages → `https://<app>/webhooks/mobile-message/{token}/inbound`
5. Top up credit and set a low-balance alert. The app also checks the balance daily (§6).

## 2. Configuration

```dotenv
MOBILEMESSAGE_BASE_URL=https://api.mobilemessage.com.au/v1
MOBILEMESSAGE_USERNAME=
MOBILEMESSAGE_PASSWORD=
MOBILEMESSAGE_SENDER=APQClean          # or +614xxxxxxxx dedicated number
MOBILEMESSAGE_WEBHOOK_TOKEN=           # long random string used in the webhook path
MOBILEMESSAGE_ALLOWED_IPS=             # optional comma list of provider webhook IPs
SMS_ENABLED=true                       # false in local/staging → messages logged, not sent
SMS_TEST_RECIPIENT=                    # staging: redirect every SMS to this number
```

`config/mobilemessage.php` maps these values and holds `max_per_request` (100) and `timeout` (15 s).

## 3. API client (single point of provider coupling)

Assumed v1 contract (to verify):

| Purpose | Request | Notes |
|---|---|---|
| Send | `POST {base}/messages` with HTTP Basic auth | Body `{"messages":[{"to","message","sender","custom_ref"}]}`. Up to 100 messages per request. |
| Send response | `{"status":"complete","total_cost":n,"results":[{"to","message","sender","custom_ref","status":"success","message_id","cost","encoding"}]}` | Per-message `status`. A failed item has `status:"error"` and an error text. |
| Lookup | `GET {base}/messages?message_id=…` (or `custom_ref=…`) | Used by the status poll fallback |
| Balance | `GET {base}/account` | Returns the credit balance |

```php
namespace App\Services\Sms;

final class MobileMessageClient implements SmsGateway
{
    public function __construct(private array $cfg) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->cfg['base_url'])
            ->withBasicAuth($this->cfg['username'], $this->cfg['password'])
            ->acceptJson()->asJson()
            ->timeout($this->cfg['timeout'])
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /** @param  OutboundSms[]  $batch  @return SendResult[] keyed by custom_ref */
    public function send(array $batch): array
    {
        $payload = ['messages' => array_map(fn (OutboundSms $m) => [
            'to'         => $m->to,                 // E.164 +614… (provider also accepts 04…)
            'message'    => $m->body,
            'sender'     => $m->sender ?? $this->cfg['sender'],
            'custom_ref' => $m->customRef,          // our idempotency/correlation key
        ], $batch)];

        $res = $this->http()->post('/messages', $payload);
        IntegrationLog::record('mobile_message', 'POST /messages', $payload, $res);

        if ($res->status() === 401) throw new SmsAuthException('Mobile Message credentials rejected');
        if ($res->status() === 402) throw new SmsCreditException('Insufficient SMS credit');
        if ($res->serverError())     throw new SmsTransientException($res->body());   // job retries

        return collect($res->json('results', []))->mapWithKeys(fn ($r) => [
            $r['custom_ref'] => new SendResult(
                ok: ($r['status'] ?? null) === 'success',
                providerId: $r['message_id'] ?? null,
                cost: $r['cost'] ?? null,
                error: $r['error'] ?? null,
            ),
        ])->all();
    }

    public function balance(): ?float
    {
        return $this->http()->get('/account')->json('credit_balance');
    }
}
```

- `SmsGateway` is an interface. `LogSmsGateway` (writes to the log and marks messages `delivered`) is bound when `SMS_ENABLED=false`, so local and staging can never text real clients.
- Phone numbers are normalised and validated as Australian mobiles (`+614XXXXXXXX`) with `libphonenumber`. Invalid numbers are rejected at client save time.

## 4. Message templates (editable in Admin → Settings → SMS)

Templates are kept to **one SMS segment** (≤ 160 GSM-7 characters) where possible. Emoji switch the encoding to UCS-2 (70 characters per segment), so the editor warns about them and shows a live character/segment counter.

| Key | Default EN | Default ES |
|---|---|---|
| `reminder_day_before` | `Hi {client_first_name}, reminder from APQ Cleaning Service: your clean is {day} {date} at {time}. Need to change? Call {business_phone}. Reply STOP to opt out` | `Hola {client_first_name}, recordatorio de APQ Cleaning Service: su limpieza es el {day} {date} a las {time}. ¿Cambios? Llame al {business_phone}. Responda STOP para no recibir más` |
| `reminder_manual` | same as above | same as above |
| `cancellation_notice` | `Hi {client_first_name}, your APQ clean on {day} {date} at {time} has been cancelled. Questions? {business_phone}` | `Hola {client_first_name}, su limpieza de APQ del {day} {date} a las {time} fue cancelada. ¿Preguntas? {business_phone}` |
| `staff_job_cancelled` | `APQ: Job cancelled – {client_display_name}, {day} {time}.` | `APQ: Trabajo cancelado – {client_display_name}, {day} {time}.` |

Rendering uses `strtr()` with escaped values. Unknown placeholders fail template validation on save. Dates and times are formatted in `Australia/Sydney` using the recipient's language, e.g. `Tue 4 Nov` / `mar 4 nov`, `9:00am`.

## 5. Sending flows

### 5.1 Automatic reminders

Scheduled command `apq:sms:send-reminders`, every 5 minutes:

```
mode = day_before_at (default 16:00):
  if now(Sydney) >= today 16:00 and < quiet_start:
     appointments where date(scheduled_start in Sydney) = tomorrow
mode = hours_before (N):
  appointments where scheduled_start between now+N h-5 min and now+N h
filter: status = scheduled, reminder_sent_at IS NULL, client.sms_opt_in, client.mobile valid,
        within quiet hours window (08:00–20:00 Sydney)
```

- Each match creates an `sms_messages` row with `custom_ref = "apt-{id}-rem"`. The unique index makes double-sends impossible even if two cron runs overlap. Matches are chunked into requests of 100 and sent by the queued job `SendSmsBatch`.
- On success: `appointments.reminder_sent_at = now()`, and the message is set to `status = sent` with its `provider_message_id`.
- Admins can turn auto-reminders off per client (`sms_opt_in = false`) or globally.

### 5.2 Manual one-click reminder

- An **📱 Send reminder** button on every appointment row (admin schedule, cancellation monitor, client page).
- A confirm sheet shows the rendered message and segment count, with **Send** to dispatch synchronously. The result shows inline ("Sent ✓" / "Failed: invalid number").
- Uses `custom_ref = "apt-{id}-man-{n}"`, so repeats are allowed but each one is recorded. The button warns if a reminder already went out in the last 12 hours.

### 5.3 Free-text SMS to a client

From the client page: a textarea with a segment counter. The message is logged with `template_key = free_text`.

### 5.4 Cancellation notices

When an appointment is cancelled, the admin can tick "Notify client by SMS" (default on for staff-caused reasons). Assigned staff get `staff_job_cancelled` if `notify_staff_on_cancel` is set.

### 5.5 Rules applied to every outbound SMS (`SmsDispatcher`)

1. Skip clients with `sms_opt_in = false` or `sms_opted_out_at` set. Transactional staff messages are exempt.
2. Respect quiet hours (automatic sends only; manual sends show a warning but are allowed).
3. Redirect to `SMS_TEST_RECIPIENT` outside production.
4. Persist first (`status = queued`), then call the API. The row is updated with the result, and every outcome is audited.

## 6. Delivery receipts, inbound messages and opt-out

**Status webhook** `POST /webhooks/mobile-message/{token}/status`:

- CSRF-exempt.
- `{token}` is compared with `hash_equals` to `MOBILEMESSAGE_WEBHOOK_TOKEN`. An optional IP allow-list also applies.
- Matches by `message_id` (fallback `custom_ref`) and maps the provider status to ours: delivered → `delivered`, failed/undeliverable/expired → `failed`. It sets `delivered_at` and `status_detail` and always returns `200`.
- Failed reminders appear on the admin dashboard ("2 reminders failed – check numbers").

**Inbound webhook** `POST /webhooks/mobile-message/{token}/inbound`:

- Stores an `sms_messages` row (`direction = inbound`, `status = received`) linked to a client by sender number.
- If the trimmed, upper-cased body is `STOP`, `STOP ALL`, `UNSUBSCRIBE`, `CANCEL` or `PARAR`, it sets `sms_opt_in = false` and `sms_opted_out_at`, audits the change and sends no further marketing or reminder messages.
- Other replies appear in Admin → SMS inbox, and an admin notification is sent. The v2 backlog adds `C` = confirm.

**Fallback poll** `apq:sms:sync-status`, every 15 minutes: looks up messages still `sent` after 30 minutes via the lookup endpoint.

**Balance check** `apq:sms:balance`, daily at 07:00: stores the balance in `settings` and emails admins if it falls below `sms.low_balance_threshold` (default 200 credits).

## 7. Compliance notes (Australia)

- **Spam Act 2003:** appointment reminders for an existing service are mainly factual, but every client message still identifies the sender ("APQ Cleaning Service") and includes an opt-out instruction. Opt-outs are honoured immediately (the law allows up to 5 business days).
- **Consent:** `sms_opt_in` defaults to true for existing clients who supplied a mobile for service contact. The client form records how consent was obtained (`sms_consent_note`).
- **Privacy:** message bodies are purged after 2 years. Phone numbers are never shown to staff unless `show_phone_to_staff` is set.

## 8. Reporting

The SMS log at `/admin/sms` can be filtered by date, client, status, trigger and template. It shows monthly totals (sent, delivered, failed, credits) and lists opted-out clients. It exports to CSV.

## 9. Tests

- `Http::fake(['api.mobilemessage.com.au/*' => …])`. Assert payload shape, chunking at 100, and that `custom_ref` uniqueness blocks duplicates.
- Quiet-hours and day-before selection use `Carbon::setTestNow()` across the AEDT/AEST boundaries (first Sunday in October and first Sunday in April).
- Webhooks: a valid token updates status, an invalid token returns 404, and `STOP` sets the opt-out.
