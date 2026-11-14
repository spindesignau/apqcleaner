<?php

namespace Tests\Feature;

use App\Actions\Invoicing\BuildInvoices;
use App\Enums\AppointmentStatus;
use App\Enums\PricingModel;
use App\Enums\TimeEntryStatus;
use App\Jobs\PushInvoiceToXero;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Xero\XeroContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class XeroInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-02 09:00', 'Australia/Sydney'));
        config(['xero.client_id' => 'cid', 'xero.client_secret' => 'secret', 'xero.webhook_key' => 'whkey']);
        $this->admin = User::factory()->admin()->create();
    }

    private function connect(): XeroConnection
    {
        return XeroConnection::create([
            'tenant_id' => 'tenant-1', 'tenant_name' => 'APQ Demo', 'connection_id' => 'conn-1',
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => now()->addMinutes(30), 'is_active' => true,
        ]);
    }

    /** An approved entry for a visit in November. */
    private function approved(Client $client, string $localStart, int $minutes, ?User $cleaner = null, ?Appointment $visit = null, ?float $price = null): TimeEntry
    {
        $cleaner ??= User::factory()->create(['name' => fake()->firstName().' Example']);
        $visit ??= Appointment::factory()->for($client)->at($localStart, 180)->create([
            'pricing_model' => $client->pricing_model, 'price' => $price ?? $client->currentPrice(), 'status' => AppointmentStatus::Completed,
        ]);
        $visit->staff()->syncWithoutDetaching([$cleaner->id]);
        $start = Carbon::parse($localStart, 'Australia/Sydney');

        return TimeEntry::factory()->for($visit)->for($cleaner)->create([
            'client_id' => $client->id, 'status' => TimeEntryStatus::Approved, 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes, 'billable_minutes' => $minutes, 'charge_rate' => $visit->price, 'pay_rate' => 35,
        ]);
    }

    private function november(): array
    {
        return [Carbon::parse('2026-11-01', 'Australia/Sydney')->startOfDay(), Carbon::parse('2026-11-30', 'Australia/Sydney')->endOfDay()];
    }

    public function test_oauth_connect_stores_encrypted_tokens_and_organisation(): void
    {
        Http::fake([
            'identity.xero.com/connect/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 1800, 'scope' => 'x']),
            'api.xero.com/connections' => Http::response([['id' => 'c-1', 'tenantId' => 't-1', 'tenantType' => 'ORGANISATION', 'tenantName' => 'APQ Cleaning']]),
        ]);

        $this->actingAs($this->admin)->post(route('admin.xero.connect'))->assertRedirectContains('login.xero.com/identity/connect/authorize');
        $state = session('xero_oauth_state');

        $this->get(route('admin.xero.callback', ['code' => 'abc', 'state' => 'wrong']))->assertSessionHasErrors('xero');
        $this->actingAs($this->admin)->withSession(['xero_oauth_state' => $state])
            ->get(route('admin.xero.callback', ['code' => 'abc', 'state' => $state]))
            ->assertRedirect(route('admin.xero.show'))->assertSessionHas('status');

        $c = XeroConnection::current();
        $this->assertSame('t-1', $c->tenant_id);
        $this->assertSame('AT', $c->access_token);
        $this->assertNotSame('AT', $c->getRawOriginal('access_token'));   // encrypted at rest
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'connect/token') && $r['grant_type'] === 'authorization_code' && $r->hasHeader('Authorization'));
    }

    public function test_expired_token_is_refreshed_before_api_call(): void
    {
        $this->connect()->update(['expires_at' => now()->subMinute()]);
        Http::fake([
            'identity.xero.com/connect/token' => Http::response(['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 1800]),
            'api.xero.com/api.xro/2.0/Organisation' => Http::response(['Organisations' => [['Name' => 'APQ Demo', 'BaseCurrency' => 'AUD']]]),
            'api.xero.com/api.xro/2.0/Accounts' => Http::response(['Accounts' => [['Code' => '200', 'Class' => 'REVENUE', 'Name' => 'Sales'], ['Code' => '260', 'Class' => 'REVENUE', 'Name' => 'Other Revenue']]]),
        ]);

        $this->actingAs($this->admin)->post(route('admin.xero.test'))->assertSessionHas('status', fn ($s) => str_contains($s, 'APQ Demo'));

        $this->assertSame('RT2', XeroConnection::current()->refresh_token);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'Organisation') && $r->header('Authorization')[0] === 'Bearer AT2' && $r->header('xero-tenant-id')[0] === 'tenant-1');
    }

    public function test_invalid_grant_marks_connection_for_reconnect(): void
    {
        $this->connect()->update(['expires_at' => now()->subMinute()]);
        Http::fake(['identity.xero.com/connect/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->actingAs($this->admin)->post(route('admin.xero.test'))->assertSessionHasErrors(['xero' => __('xero.reconnect_needed')]);
        $this->assertNull(XeroConnection::current());
    }

    public function test_builder_fixed_price_one_line_per_visit_with_gst_and_locks_entries(): void
    {
        $client = Client::factory()->create(['fixed_price' => 250]);
        $a = $this->approved($client, '2026-11-03 09:00', 190);
        $partner = User::factory()->create(['name' => 'Rosa Example']);
        $this->approved($client, '2026-11-03 09:00', 185, $partner, $a->appointment);   // second cleaner, same visit
        $this->approved($client, '2026-11-17 09:00', 170);

        [$from, $to] = $this->november();
        $invoices = app(BuildInvoices::class)->build($from, $to, 'draft', false);

        $this->assertCount(1, $invoices);
        $inv = $invoices->first()->load('lines');
        $this->assertCount(2, $inv->lines);   // one per visit, not per cleaner
        $this->assertEquals(500, $inv->subtotal);
        $this->assertEquals(50, $inv->gst);
        $this->assertEquals(550, $inv->total);
        $this->assertStringContainsString('Tue 3 Nov 2026', $inv->lines[0]->description);
        $this->assertStringContainsString('Rosa', $inv->lines[0]->description);
        $this->assertSame(3, TimeEntry::where('status', TimeEntryStatus::Invoiced->value)->whereNotNull('invoice_line_id')->count());
        $this->assertSame(AppointmentStatus::Invoiced, $a->appointment->fresh()->status);

        // Nothing left to invoice a second time.
        $this->assertCount(0, app(BuildInvoices::class)->build($from, $to, 'draft', false));
    }

    public function test_builder_hourly_sums_billable_time_of_all_cleaners(): void
    {
        $client = Client::factory()->create(['pricing_model' => PricingModel::Hourly, 'hourly_rate' => 60, 'fixed_price' => null]);
        $a = $this->approved($client, '2026-11-05 09:00', 150, price: 60);
        $this->approved($client, '2026-11-05 09:00', 135, User::factory()->create(), $a->appointment);

        [$from, $to] = $this->november();
        $line = app(BuildInvoices::class)->build($from, $to, 'draft', false)->first()->lines->first();

        $this->assertEquals(4.75, (float) $line->quantity);   // (150 + 135) / 60
        $this->assertEquals(60, $line->unit_amount);
        $this->assertEquals(285, $line->line_amount);
    }

    public function test_per_visit_clients_get_one_invoice_per_visit(): void
    {
        $client = Client::factory()->create(['invoice_frequency' => 'per_visit', 'fixed_price' => 200]);
        $this->approved($client, '2026-11-03 09:00', 120);
        $this->approved($client, '2026-11-10 09:00', 120);

        [$from, $to] = $this->november();
        $this->assertCount(2, app(BuildInvoices::class)->build($from, $to, 'draft', false));
    }

    public function test_unapproved_and_unpriced_visits_are_excluded_with_warnings(): void
    {
        $client = Client::factory()->create(['fixed_price' => 200]);
        $this->approved($client, '2026-11-03 09:00', 120);
        $this->approved($client, '2026-11-04 09:00', 120)->update(['status' => TimeEntryStatus::Submitted]);
        $noPrice = Client::factory()->create(['fixed_price' => null]);
        $this->approved($noPrice, '2026-11-05 09:00', 120, price: null)->update(['charge_rate' => null]);

        [$from, $to] = $this->november();
        $preview = app(BuildInvoices::class)->preview($from, $to);
        $first = $preview->firstWhere('client.id', $client->id);
        $this->assertSame(1, $first['visits']->count());
        $this->assertContains(trans_choice('invoices.warn_unapproved', 1, ['count' => 1]), $first['warnings']);
        $this->assertContains(__('invoices.warn_no_price'), $preview->firstWhere('client.id', $noPrice->id)['warnings']);

        $this->assertCount(1, app(BuildInvoices::class)->build($from, $to, 'draft', false));   // unpriced client skipped
    }

    public function test_push_creates_draft_with_idempotency_key_and_matches_contact_by_email(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250, 'email' => 'jo@example.com']);
        $this->approved($client, '2026-11-03 09:00', 180);
        [$from, $to] = $this->november();
        $invoice = app(BuildInvoices::class)->build($from, $to, 'draft', false)->first();

        Http::fake([
            'api.xero.com/api.xro/2.0/Contacts*' => Http::response(['Contacts' => [['ContactID' => 'contact-9']]]),
            'api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Invoices' => [[
                'InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-0042', 'Status' => 'DRAFT', 'Total' => 275.0, 'AmountDue' => 275.0, 'ValidationErrors' => [],
            ]]]),
        ]);

        PushInvoiceToXero::dispatchSync($invoice);

        $invoice->refresh();
        $this->assertSame(Invoice::SYNC_SYNCED, $invoice->sync_status);
        $this->assertSame('INV-0042', $invoice->xero_invoice_number);
        $this->assertSame('contact-9', $client->fresh()->xero_contact_id);
        Http::assertSent(function (Request $r) use ($invoice) {
            if (! str_contains($r->url(), '/Invoices')) {
                return false;
            }
            $body = $r->data()['Invoices'][0];

            return $r->header('Idempotency-Key')[0] === $invoice->idempotency_key
                && $body['Status'] === 'DRAFT' && $body['Type'] === 'ACCREC' && $body['LineAmountTypes'] === 'Exclusive'
                && $body['Contact']['ContactID'] === 'contact-9' && $body['LineItems'][0]['TaxType'] === 'OUTPUT'
                && $body['LineItems'][0]['AccountCode'] === '200' && (float) $body['LineItems'][0]['UnitAmount'] === 250.0;
        });
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/Email'));
    }

    public function test_contact_is_created_when_not_found(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250, 'email' => 'new@example.com', 'billing_name' => 'New Example']);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/Contacts') && $r->method() === 'GET') {
                return Http::response(['Contacts' => []]);
            }
            if (str_contains($r->url(), '/Contacts')) {
                return Http::response(['Contacts' => [['ContactID' => 'created-1']]]);
            }
        });

        $this->assertSame('created-1', app(XeroContacts::class)->ensure($client));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['Contacts'][0]['Name'] ?? null) === 'New Example');
    }

    public function test_authorised_invoice_is_emailed_and_gets_online_link(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250, 'email' => 'jo@example.com', 'xero_contact_id' => 'contact-9']);
        $this->approved($client, '2026-11-03 09:00', 180);
        [$from, $to] = $this->november();
        $invoice = app(BuildInvoices::class)->build($from, $to, 'authorised', true)->first();

        Http::fake([
            'api.xero.com/api.xro/2.0/Invoices/inv-1/OnlineInvoice' => Http::response(['OnlineInvoices' => [['OnlineInvoiceUrl' => 'https://in.xero.com/abc']]]),
            'api.xero.com/api.xro/2.0/Invoices/inv-1/Email' => Http::response(null, 204),
            'api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-1', 'Status' => 'AUTHORISED', 'Total' => 275, 'AmountDue' => 275]]]),
        ]);

        PushInvoiceToXero::dispatchSync($invoice);

        $invoice->refresh();
        $this->assertSame('AUTHORISED', $invoice->xero_status);
        $this->assertNotNull($invoice->emailed_at);
        $this->assertSame('https://in.xero.com/abc', $invoice->xero_online_url);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Invoices') && ($r->data()['Invoices'][0]['Status'] ?? null) === 'AUTHORISED');
    }

    public function test_xero_validation_error_marks_failed_without_retry_and_admin_can_retry(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250, 'xero_contact_id' => 'contact-9']);
        $this->approved($client, '2026-11-03 09:00', 180);
        [$from, $to] = $this->november();
        $invoice = app(BuildInvoices::class)->build($from, $to, 'draft', false)->first();

        Http::fake(['api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Elements' => [['ValidationErrors' => [['Message' => 'Account code 200 is not a valid code']]]]], 400)]);
        PushInvoiceToXero::dispatchSync($invoice);

        $invoice->refresh();
        $this->assertSame(Invoice::SYNC_FAILED, $invoice->sync_status);
        $this->assertSame('Account code 200 is not a valid code', $invoice->last_error);
        $this->actingAs($this->admin)->get(route('admin.invoices.index', ['state' => 'failed']))->assertSee('Account code 200 is not a valid code');

        Queue::fake();
        $this->post(route('admin.invoices.retry', $invoice))->assertRedirect();
        Queue::assertPushed(PushInvoiceToXero::class);
        $this->assertSame(Invoice::SYNC_PENDING, $invoice->fresh()->sync_status);
    }

    public function test_retry_after_timeout_reuses_the_same_idempotency_key(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250, 'xero_contact_id' => 'contact-9']);
        $this->approved($client, '2026-11-03 09:00', 180);
        [$from, $to] = $this->november();
        $invoice = app(BuildInvoices::class)->build($from, $to, 'draft', false)->first();

        Http::fakeSequence('api.xero.com/api.xro/2.0/Invoices*')
            ->push(['Title' => 'Service unavailable'], 503)
            ->push(['Invoices' => [['InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-1', 'Status' => 'DRAFT', 'Total' => 275, 'AmountDue' => 275]]]);

        PushInvoiceToXero::dispatchSync($invoice);   // 503 → left pending for retry
        $this->assertSame(Invoice::SYNC_PENDING, $invoice->fresh()->sync_status);
        PushInvoiceToXero::dispatchSync($invoice->fresh());
        $this->assertSame(Invoice::SYNC_SYNCED, $invoice->fresh()->sync_status);

        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_contains($r->url(), '/Invoices'))
            ->map(fn ($r) => $r->header('Idempotency-Key')[0])->unique();
        $this->assertSame([$invoice->idempotency_key], $keys->values()->all());
    }

    public function test_void_draft_deletes_in_xero_and_unlocks_timesheets(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250]);
        $entry = $this->approved($client, '2026-11-03 09:00', 180);
        [$from, $to] = $this->november();
        $invoice = app(BuildInvoices::class)->build($from, $to, 'draft', false)->first();
        $invoice->update(['xero_invoice_id' => 'inv-1', 'xero_status' => 'DRAFT', 'sync_status' => Invoice::SYNC_SYNCED]);

        Http::fake(function (Request $r) {
            if ($r->method() === 'GET') {
                return Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'DRAFT', 'AmountPaid' => 0]]]);
            }

            return Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'DELETED']]]);
        });

        $this->actingAs($this->admin)->post(route('admin.invoices.void', $invoice))->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r->data()['Invoices'][0]['Status'] ?? null) === 'DELETED');
        $this->assertSame(Invoice::SYNC_VOIDED, $invoice->fresh()->sync_status);
        $entry->refresh();
        $this->assertSame(TimeEntryStatus::Approved, $entry->status);
        $this->assertNull($entry->invoice_line_id);
        $entry->update(['billable_minutes' => 175]);   // editable again
        $this->assertSame(AppointmentStatus::Completed, $entry->appointment->fresh()->status);
    }

    public function test_paid_invoice_cannot_be_voided(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250]);
        $this->approved($client, '2026-11-03 09:00', 180);
        [$from, $to] = $this->november();
        $invoice = app(BuildInvoices::class)->build($from, $to, 'authorised', false)->first();
        $invoice->update(['xero_invoice_id' => 'inv-1', 'xero_status' => 'AUTHORISED', 'sync_status' => Invoice::SYNC_SYNCED]);
        Http::fake(['*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-1', 'Status' => 'AUTHORISED', 'AmountPaid' => 100]]])]);

        $this->actingAs($this->admin)->post(route('admin.invoices.void', $invoice))->assertSessionHasErrors(['invoice' => __('invoices.paid_cannot_void')]);
        $this->assertSame(Invoice::SYNC_SYNCED, $invoice->fresh()->sync_status);
    }

    public function test_webhook_verifies_signature_and_marks_invoice_paid(): void
    {
        $this->connect();
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id, 'reference' => 'APQ-1', 'idempotency_key' => fake()->uuid(), 'issue_date' => '2026-12-01', 'due_date' => '2026-12-08',
            'subtotal' => 100, 'gst' => 10, 'total' => 110, 'xero_invoice_id' => 'inv-7', 'xero_status' => 'AUTHORISED', 'sync_status' => Invoice::SYNC_SYNCED,
        ]);
        $body = json_encode(['events' => [['resourceId' => 'inv-7', 'eventCategory' => 'INVOICE', 'eventType' => 'UPDATE', 'tenantId' => 'tenant-1']]]);
        Http::fake(['api.xero.com/api.xro/2.0/Invoices/inv-7' => Http::response(['Invoices' => [['InvoiceID' => 'inv-7', 'Status' => 'PAID', 'AmountDue' => 0, 'Total' => 110, 'FullyPaidOnDate' => '/Date(1764720000000+0000)/']]])]);

        $this->call('POST', '/webhooks/xero', [], [], [], ['HTTP_X_XERO_SIGNATURE' => 'bad', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $sig = base64_encode(hash_hmac('sha256', $body, 'whkey', true));
        $this->call('POST', '/webhooks/xero', [], [], [], ['HTTP_X_XERO_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $invoice->refresh();
        $this->assertSame('PAID', $invoice->xero_status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertEquals(0, $invoice->xero_amount_due);
    }

    public function test_hourly_sync_updates_open_invoices(): void
    {
        $this->connect();
        $client = Client::factory()->create();
        $invoice = Invoice::create([
            'client_id' => $client->id, 'reference' => 'APQ-1', 'idempotency_key' => fake()->uuid(), 'issue_date' => '2026-12-01', 'due_date' => '2026-12-08',
            'subtotal' => 100, 'gst' => 10, 'total' => 110, 'xero_invoice_id' => 'inv-8', 'xero_status' => 'AUTHORISED', 'sync_status' => Invoice::SYNC_SYNCED,
        ]);
        Http::fake(['api.xero.com/api.xro/2.0/Invoices*' => Http::response(['Invoices' => [['InvoiceID' => 'inv-8', 'Status' => 'AUTHORISED', 'AmountDue' => 40, 'Total' => 110]]])]);

        $this->artisan('apq:xero:sync-invoices')->assertSuccessful();

        $this->assertEquals(40, $invoice->fresh()->xero_amount_due);
    }

    public function test_run_page_preview_and_create_dispatches_pushes(): void
    {
        $this->connect();
        $client = Client::factory()->create(['fixed_price' => 250, 'display_name' => 'Harbour Example']);
        $this->approved($client, '2026-11-03 09:00', 180);
        Queue::fake();

        $this->actingAs($this->admin)->get(route('admin.invoices.run'))
            ->assertOk()->assertSee('Harbour Example')->assertSee('$275.00');   // defaults to last month (November)

        $this->post(route('admin.invoices.run.store'), ['from' => '2026-11-01', 'to' => '2026-11-30', 'mode' => 'draft', 'clients' => [$client->id]])
            ->assertRedirect();

        Queue::assertPushed(PushInvoiceToXero::class, 1);
        $this->assertSame(1, Invoice::count());
        $this->get(route('admin.invoices.runs.show', Invoice::first()->invoice_run_id))->assertOk()->assertSee('Harbour Example');
        $this->get(route('admin.invoices.show', Invoice::first()))->assertOk();
        $this->get(route('admin.xero.show'))->assertOk()->assertSee('APQ Demo');
    }

    public function test_cannot_create_invoices_without_xero_and_staff_are_blocked(): void
    {
        $this->actingAs($this->admin)->post(route('admin.invoices.run.store'), ['from' => '2026-11-01', 'to' => '2026-11-30', 'mode' => 'draft', 'clients' => [1]])
            ->assertStatus(422);

        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('admin.invoices.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.xero.show'))->assertForbidden();
    }
}
