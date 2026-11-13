# APQ Cleaning Service — Xero Accounting Integration

| | |
|---|---|
| **Library** | `xeroapi/xero-php-oauth2` (official Xero PHP SDK) + `league/oauth2-client` (`GenericProvider`) for the auth-code flow |
| **App type** | Xero "Web app" (auth code flow, confidential client) registered at developer.xero.com |
| **Redirect URI** | `https://<app-domain>/admin/xero/callback` |
| **Tenancy** | Single organisation (APQ's Xero file). The first connected tenant is stored. Multiple tenants are rejected with a message. |

## 1. Configuration

`.env`

```dotenv
XERO_CLIENT_ID=
XERO_CLIENT_SECRET=
XERO_REDIRECT_URI="${APP_URL}/admin/xero/callback"
XERO_WEBHOOK_KEY=
XERO_SCOPES="openid profile email offline_access accounting.contacts accounting.settings.read accounting.invoices"
```

> **Scopes — check when registering the app.** Xero is moving from broad scopes (`accounting.transactions`) to **granular scopes** (e.g. `accounting.invoices`, `accounting.payments`). Newly created apps are expected to use the granular set. Confirm the exact names on the app's *Configuration → Scopes* page at developer.xero.com and set `XERO_SCOPES` to match. If the app is an older one still on broad scopes, use `accounting.transactions` in place of `accounting.invoices`. Nothing else in this design changes.

`config/xero.php`

```php
return [
    'client_id'     => env('XERO_CLIENT_ID'),
    'client_secret' => env('XERO_CLIENT_SECRET'),
    'redirect_uri'  => env('XERO_REDIRECT_URI'),
    'scopes'        => explode(' ', env('XERO_SCOPES')),
    'webhook_key'   => env('XERO_WEBHOOK_KEY'),
    'urls' => [
        'authorize'   => 'https://login.xero.com/identity/connect/authorize',
        'token'       => 'https://identity.xero.com/connect/token',
        'connections' => 'https://api.xero.com/connections',
        'revoke'      => 'https://identity.xero.com/connect/revocation',
    ],
];
```

## 2. OAuth 2.0 connection flow

| Step | Route | Behaviour |
|---|---|---|
| 1 | `GET /admin/xero/connect` (admin) | Builds the authorise URL with a random `state` (stored in the session) and redirects to Xero. |
| 2 | `GET /admin/xero/callback` | Validates `state`, exchanges the `code` for tokens, calls `GET /connections` and stores `tenantId`, `tenantName`, tokens (encrypted) and `expires_at`. |
| 3 | `POST /admin/xero/disconnect` | `DELETE /connections/{id}`, revokes the refresh token, deactivates the row. |
| — | Settings → Xero card | Shows organisation name, connected by, token expiry, last successful call, and a **Test connection** button (`GET /Organisation`). |

### Token lifecycle

- Access tokens last **30 minutes**. Refresh tokens are **single-use** and expire after **60 days** without use.
- `XeroTokenManager::accessToken()` refreshes when `expires_at < now()+5min`. The refresh runs inside `Cache::lock('xero-token-refresh', 30)` so two queue workers never use the same refresh token at once (a reused refresh token is invalidated by Xero).
- A scheduled `apq:xero:keepalive` refreshes the token weekly, so a quiet month never lets the 60-day refresh token lapse.
- On `invalid_grant`, the connection is marked inactive, the admin dashboard shows **"Reconnect Xero"**, and admins get an email.

```php
final class XeroTokenManager
{
    public function __construct(private XeroOAuthProvider $provider) {}

    public function connection(): XeroConnection
    {
        return XeroConnection::where('is_active', true)->latest()->firstOrFail();
    }

    public function accessToken(): string
    {
        $c = $this->connection();
        if ($c->expires_at->gt(now()->addMinutes(5))) {
            return $c->access_token;
        }

        return Cache::lock('xero-token-refresh', 30)->block(20, function () {
            $c = $this->connection()->fresh();                     // another worker may have refreshed
            if ($c->expires_at->gt(now()->addMinutes(5))) return $c->access_token;

            try {
                $t = $this->provider->getAccessToken('refresh_token', ['refresh_token' => $c->refresh_token]);
            } catch (IdentityProviderException $e) {
                $c->update(['is_active' => false]);
                XeroDisconnected::dispatch($c, $e->getMessage());
                throw $e;
            }

            $c->update([
                'access_token'  => $t->getToken(),
                'refresh_token' => $t->getRefreshToken(),
                'expires_at'    => Carbon::createFromTimestamp($t->getExpires()),
            ]);
            return $t->getToken();
        });
    }

    public function accountingApi(): \XeroAPI\XeroPHP\Api\AccountingApi
    {
        $config = \XeroAPI\XeroPHP\Configuration::getDefaultConfiguration()
            ->setAccessToken($this->accessToken());
        return new \XeroAPI\XeroPHP\Api\AccountingApi(new \GuzzleHttp\Client(['timeout' => 30]), $config);
    }

    public function tenantId(): string { return $this->connection()->tenant_id; }
}
```

## 3. Contact synchronisation

`XeroContactService::ensureContact(Client $client): string` returns a `ContactID`:

1. If `clients.xero_contact_id` is set, return it.
2. Search Xero: `getContacts($tenant, where: 'EmailAddress=="…"')`. If nothing matches, search by `Name=="<billing_name>"`. Values are escaped; `searchTerm` is used for fuzzy matching in the admin "Link to Xero contact" picker.
3. If nothing is found, `createContacts` with Name, FirstName, LastName, EmailAddress, Phones (MOBILE) and Addresses (STREET: line1, city = suburb, region = NSW, postal code, country AU).
4. Save `xero_contact_id` and `xero_synced_at`.

The admin client screen offers **Link existing Xero contact** (typeahead) to avoid duplicate contacts on first sync. A bulk **"Match all clients"** tool matches by email or name and shows a review table before saving.

## 4. Service invoices

### 4.1 Building an invoice (local first)

`BuildClientInvoiceAction(Client $client, CarbonPeriod $period)`:

- Collects `time_entries` with `status = approved`, `client_id = $client`, `started_at` in the period, `is_legacy = 0`.
- Groups them by appointment. **One line per visit:**

| Pricing model | Line |
|---|---|
| hourly | `Description` = `"{Service} – {Tue 4 Nov 2026} – {cleaner first names} ({h} h)"`, `Quantity` = Σ billable_minutes / 60 (4 dp), `UnitAmount` = charge rate in force on the visit date |
| fixed | `Quantity` = 1, `UnitAmount` = fixed price; the description notes the hours worked for reference |

- Adds unbilled `cancellations` with `fee_decision = pending_batch` if the admin chose *Add to next invoice* instead of a separate fee invoice.
- Calculates `subtotal`, `gst = round(subtotal × 0.10, 2)` and `total`, and writes `invoices` + `invoice_lines` with `sync_status = pending`, a fresh UUID `idempotency_key`, and reference `APQ-{YYYYMM}-{client id}`.
- Links each time entry's `invoice_line_id` and sets `status = invoiced` **in the same DB transaction**. The entries are now locked.

### 4.2 Pushing to Xero (`PushInvoiceToXero` queued job)

```php
final class PushInvoiceToXero implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $tries = 5;
    public function backoff(): array { return [30, 120, 600, 1800]; }
    public function uniqueId(): string { return 'xero-invoice-'.$this->invoice->id; }

    public function __construct(public Invoice $invoice, public bool $authorise, public bool $email) {}

    public function handle(XeroTokenManager $xero, XeroContactService $contacts): void
    {
        $inv = $this->invoice->fresh(['lines', 'client']);
        if ($inv->xero_invoice_id) return;                       // already synced
        $inv->update(['sync_status' => 'pushing']);

        $api = $xero->accountingApi();
        $tenant = $xero->tenantId();

        $model = (new XInvoice())
            ->setType(XInvoice::TYPE_ACCREC)
            ->setContact((new XContact())->setContactId($contacts->ensureContact($inv->client)))
            ->setDate($inv->issue_date->toDateString())
            ->setDueDate($inv->due_date->toDateString())
            ->setReference($inv->reference)
            ->setLineAmountTypes(LineAmountTypes::EXCLUSIVE)
            ->setStatus($this->authorise ? XInvoice::STATUS_AUTHORISED : XInvoice::STATUS_DRAFT)
            ->setLineItems($inv->lines->map(fn ($l) => (new LineItem())
                ->setDescription($l->description)
                ->setQuantity((float) $l->quantity)
                ->setUnitAmount((float) $l->unit_amount)
                ->setAccountCode($l->account_code)
                ->setTaxType($l->tax_type)              // 'OUTPUT' = GST on Income (AU)
                ->setItemCode($l->item_code))->all());

        if ($theme = settings('xero.branding_theme_id')) $model->setBrandingThemeId($theme);

        try {
            $result = $api->createInvoices(
                $tenant,
                (new XInvoices())->setInvoices([$model]),
                summarize_errors: false,
                unitdp: 4,
                idempotency_key: $inv->idempotency_key,   // retry-safe: Xero returns the original result
            )->getInvoices()[0];
        } catch (ApiException $e) {
            $this->recordFailure($inv, $e);                // 400 validation → failed (no retry); 429/5xx → rethrow for backoff
            return;
        }

        if ($result->getHasErrors()) {
            $inv->update(['sync_status' => 'failed',
                'last_error' => collect($result->getValidationErrors())->pluck('message')->implode('; ')]);
            return;
        }

        $inv->update([
            'xero_invoice_id'     => $result->getInvoiceId(),
            'xero_invoice_number' => $result->getInvoiceNumber(),
            'xero_status'         => $result->getStatus(),
            'xero_total'          => $result->getTotal(),
            'xero_amount_due'     => $result->getAmountDue(),
            'sync_status'         => 'synced',
            'synced_at'           => now(),
        ]);

        if ($this->authorise) {
            $inv->update(['xero_online_url' => $api->getOnlineInvoice($tenant, $result->getInvoiceId())
                ->getOnlineInvoices()[0]?->getOnlineInvoiceUrl()]);
            if ($this->email && $inv->client->email) {
                $api->emailInvoice($tenant, $result->getInvoiceId(), new RequestEmpty(), (string) Str::uuid());
                $inv->update(['emailed_at' => now()]);
            }
        }
        audit('invoice.pushed', $inv);
    }
}
```

Notes:

- Xero only emails **AUTHORISED** (approved) sales invoices, and the contact must have an email address. Draft mode never emails. The UI disables "Email" when Draft is selected.
- If Xero's GST total differs from the local total by more than $0.01 (rounding per line vs per invoice), the Xero figures win and are stored. A sync note is shown.
- `summarize_errors=false` returns per-invoice validation errors instead of failing the whole batch.

### 4.3 Batch runs

- `/admin/invoices/run`: choose period, client filter (default: all clients with approved, uninvoiced time whose `invoice_frequency` matches), mode **Draft / Authorised**, **Email via Xero** checkbox.
- Preview: client, visits, hours, subtotal, GST, total, and warnings (no Xero contact, no email, unapproved entries in the period).
- Confirm creates an `invoice_runs` row, builds local invoices, then dispatches a `Bus::batch()` of `PushInvoiceToXero` jobs, throttled with `RateLimited('xero')` (`Limit::perMinute(50)`). Xero's limits are 60 calls/min, 5,000/day and 5 concurrent per tenant.
- The progress bar polls the batch (Livewire `wire:poll.2s`). Finished runs show per-invoice results and **Retry failed**.

### 4.4 Single invoice

From the timesheet grid or client page: **Invoice now** builds and pushes one invoice synchronously (via the same job, `dispatchSync`).

## 5. Cancellation fee invoices

`CreateCancellationFeeInvoice(Cancellation $c, bool $authorise, bool $email)`:

| Field | Value |
|---|---|
| Reference | `CXL-{appointment_id}` |
| Line | `"Late cancellation fee – {Regular clean} scheduled {Tue 4 Nov 2026 9:00 am} (notice: {10.5} h)"` |
| Quantity | 1 |
| UnitAmount | `fee_amount_ex_gst` |
| AccountCode | `settings('xero.cancellation_account_code')` (default `260`) |
| TaxType | `OUTPUT` |

The invoice is created with its own idempotency key and pushed with the same `PushInvoiceToXero` job. `cancellations.invoice_id` and `fee_decision = charged` are set.

The admin can choose **Separate invoice now** (default) or **Add to next regular invoice**.

## 6. Payment status sync

- **Webhooks** (preferred). The Xero app subscribes to `INVOICE` events. Route `POST /webhooks/xero`, CSRF-exempt:
  - Verify `x-xero-signature` = `base64(hmac_sha256(rawBody, XERO_WEBHOOK_KEY))` with `hash_equals`. Respond `401` on mismatch and `200` on success, within 5 s. This is required for Xero's "intent to receive" validation.
  - For each event with `eventCategory = INVOICE`, dispatch `RefreshXeroInvoice(resourceId)`. That job calls `getInvoice` and updates `xero_status`, `xero_amount_due`, and `paid_at` when the status is `PAID`.
- **Fallback poll** `apq:xero:sync-invoices` hourly: `getInvoices(ifModifiedSince: last_sync, iDs: [...open invoice ids])`.

## 7. Corrections and voiding

- Editing time already invoiced is blocked. The admin flow is:
  - Draft invoice: **Void and rebuild**. This calls `updateInvoice` with status `DELETED` (for draft) or `VOIDED` (for authorised with no payments), sets entries back to `approved`, and rebuilds.
  - Paid or part-paid invoice: create a **credit note** in Xero manually. The app shows a link to the invoice in Xero (`https://go.xero.com/AccountsReceivable/View.aspx?InvoiceID=…`).
- Every action is audited.

## 8. Error handling matrix

| Xero response | Handling |
|---|---|
| 400 ValidationException | `sync_status = failed`, message stored and shown. No retry. Admin fixes (e.g. archived contact, invalid account code) → **Retry**. |
| 401 | Force token refresh once, then retry. If it fails again, mark the connection inactive. |
| 403 / AuthorizationUnsuccessful | The tenant was disconnected. Show a reconnect banner. |
| 404 | Contact or invoice deleted in Xero. Clear the local `xero_*_id` and re-create (contacts only). |
| 429 | Read the `Retry-After` header and `release($retryAfter)`. |
| 5xx / timeouts | Exponential backoff (5 tries). The idempotency key prevents duplicates. |

Every request and response is written to `integration_logs` (tokens redacted).

## 9. Testing

- Unit: invoice builder maths (GST rounding, minimums, increments, multi-cleaner, fixed price, rate history by date).
- Feature: `Http::fake()` / a mocked `AccountingApi` binding. Assert the idempotency key is reused on retry, and that entries are locked after build.
- Manual: Xero **Demo Company (AU)** connected in staging before go-live. Check account codes `200`/`260` exist and tax type `OUTPUT` is valid.
