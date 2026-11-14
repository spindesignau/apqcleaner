<?php

namespace Tests\Feature;

use App\Actions\Cancellations\CancellationFee;
use App\Enums\AppointmentStatus;
use App\Enums\TimeEntryStatus;
use App\Jobs\PushInvoiceToXero;
use App\Models\Appointment;
use App\Models\Cancellation;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Sms\MobileMessageGateway;
use App\Services\Sms\SmsDispatcher;
use App\Services\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CancellationsAndSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cleaner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00', 'Australia/Sydney'));   // Monday
        config(['mobilemessage.enabled' => false, 'mobilemessage.test_recipient' => null]);
        $this->admin = User::factory()->admin()->create();
        $this->cleaner = User::factory()->create(['name' => 'Rosa Example', 'mobile' => '+61411111111']);
        $this->client = Client::factory()->create(['display_name' => 'Jo Smith – Manly', 'fixed_price' => 240, 'mobile' => '+61422222222', 'sms_opt_in' => true]);
    }

    private function visit(string $localStart, ?Client $client = null): Appointment
    {
        $a = Appointment::factory()->for($client ?? $this->client)->at($localStart, 180)->create(['price' => ($client ?? $this->client)->fixed_price]);
        $a->staff()->attach($this->cleaner);

        return $a;
    }

    // ---- Fee rules ----

    public function test_fee_quote_rules(): void
    {
        $fees = app(CancellationFee::class);
        $now = Carbon::now('Australia/Sydney');
        $soon = $this->visit('2026-11-17 09:00');   // 23 h away
        $later = $this->visit('2026-11-20 09:00');  // ~4 days away

        $q = $fees->quote($soon, 'client_cancelled', $now);
        $this->assertTrue($q['eligible']);
        $this->assertEquals(120, $q['amount']);   // 50% of $240
        $this->assertEquals(23, $q['hours_notice']);

        $this->assertFalse($fees->quote($later, 'client_cancelled', $now)['eligible']);
        $this->assertFalse($fees->quote($soon, 'staff_unavailable', $now)['eligible']);
        $this->assertFalse($fees->quote($soon, 'weather', $now)['eligible']);

        $this->client->update(['cancellation_fee_type' => 'fixed', 'cancellation_fee_value' => 60]);
        $this->assertEquals(60, $fees->quote($soon->fresh(), 'client_no_access', $now)['amount']);

        $this->client->update(['cancellation_fee_type' => 'none']);
        $this->assertFalse($fees->quote($soon->fresh(), 'client_cancelled', $now)['eligible']);

        // Notice backdated to 2 days ago → outside the window.
        $this->client->update(['cancellation_fee_type' => 'percent_of_visit']);
        $this->assertFalse($fees->quote($soon->fresh(), 'client_cancelled', $now->copy()->subDays(2))['eligible']);
    }

    // ---- Cancelling ----

    public function test_late_cancellation_charges_fee_invoice_and_notifies_by_sms(): void
    {
        Queue::fake();
        $visit = $this->visit('2026-11-17 09:00');

        $this->actingAs($this->admin)->get(route('admin.cancellations.create', $visit))->assertOk()->assertSee('$120.00', false);

        $this->post(route('admin.cancellations.store', $visit), [
            'reason' => 'client_cancelled', 'notice_date' => '2026-11-16', 'notice_time' => '10:00',
            'decision' => 'charged', 'amount' => '120', 'mode' => 'authorised', 'email' => '1',
            'notify_client' => '1', 'notify_staff' => '1',
        ])->assertRedirect(route('admin.cancellations.index'));

        $this->assertSame(AppointmentStatus::CancelledLate, $visit->fresh()->status);
        $c = Cancellation::sole();
        $this->assertSame('charged', $c->fee_decision);
        $invoice = $c->invoice->load('lines');
        $this->assertSame('cancellation_fee', $invoice->type);
        $this->assertSame('CXL-'.$visit->id, $invoice->reference);
        $this->assertEquals(132, $invoice->total);   // 120 + GST
        $this->assertSame('260', $invoice->lines[0]->account_code);
        $this->assertStringContainsString('Late cancellation fee', $invoice->lines[0]->description);
        $this->assertTrue($invoice->send_email);
        Queue::assertPushed(PushInvoiceToXero::class, fn ($job) => $job->invoice->is($invoice));

        $toClient = SmsMessage::where('client_id', $this->client->id)->sole();
        $this->assertSame('cancellation', $toClient->template_key);
        $this->assertSame('sent', $toClient->status);
        $this->assertStringContainsString('Hi Jo', $toClient->body);
        $this->assertStringContainsString('cancelled', $toClient->body);
        $this->assertSame('+61411111111', SmsMessage::where('user_id', $this->cleaner->id)->sole()->to_number);

        // Gone from the cleaner's list.
        Carbon::setTestNow(Carbon::parse('2026-11-17 07:00', 'Australia/Sydney'));
        $this->actingAs($this->cleaner)->get(route('jobs.today'))->assertDontSee('Jo Smith');
    }

    public function test_waiving_needs_a_reason(): void
    {
        $visit = $this->visit('2026-11-17 09:00');
        $payload = ['reason' => 'client_cancelled', 'notice_date' => '2026-11-16', 'notice_time' => '10:00', 'decision' => 'waived'];

        $this->actingAs($this->admin)->post(route('admin.cancellations.store', $visit), $payload)->assertSessionHasErrors('waive_reason');
        $this->assertSame(AppointmentStatus::Scheduled, $visit->fresh()->status);

        $this->post(route('admin.cancellations.store', $visit), $payload + ['waive_reason' => 'Family emergency'])->assertRedirect();
        $this->assertSame('waived', Cancellation::sole()->fee_decision);
        $this->assertSame(0, Invoice::count());
    }

    public function test_early_or_staff_caused_cancellation_has_no_fee(): void
    {
        $visit = $this->visit('2026-11-20 09:00');
        $this->actingAs($this->admin)->post(route('admin.cancellations.store', $visit), [
            'reason' => 'client_cancelled', 'notice_date' => '2026-11-16', 'notice_time' => '10:00', 'decision' => 'charged', 'amount' => '120',
        ]);
        $this->assertSame('not_applicable', Cancellation::sole()->fee_decision);
        $this->assertSame(AppointmentStatus::Cancelled, $visit->fresh()->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_no_access_status_and_started_visits_cannot_be_cancelled(): void
    {
        $visit = $this->visit('2026-11-17 09:00');
        $this->actingAs($this->admin)->post(route('admin.cancellations.store', $visit), [
            'reason' => 'client_no_access', 'notice_date' => '2026-11-16', 'notice_time' => '10:00', 'decision' => 'waived', 'waive_reason' => 'Goodwill',
        ]);
        $this->assertSame(AppointmentStatus::NoAccess, $visit->fresh()->status);

        $started = $this->visit('2026-11-16 09:00');
        $started->update(['status' => AppointmentStatus::InProgress]);
        TimeEntry::factory()->for($started)->for($this->cleaner)->create(['client_id' => $this->client->id, 'status' => TimeEntryStatus::Running]);
        $this->post(route('admin.cancellations.store', $started), [
            'reason' => 'client_cancelled', 'notice_date' => '2026-11-16', 'notice_time' => '10:00', 'decision' => 'charged',
        ])->assertSessionHasErrors('reason');
    }

    // ---- Reminders ----

    public function test_day_before_reminders_respect_time_quiet_hours_opt_out_and_never_repeat(): void
    {
        $tomorrow = $this->visit('2026-11-17 09:00');
        $optedOut = Client::factory()->create(['sms_opt_in' => false, 'mobile' => '+61433333333']);
        $this->visit('2026-11-17 11:00', $optedOut);
        $this->visit('2026-11-18 09:00');   // not tomorrow

        $this->artisan('apq:sms:send-reminders')->expectsOutput('Not reminder time yet.');   // 10:00 < 16:00
        $this->assertSame(0, SmsMessage::count());

        Carbon::setTestNow(Carbon::parse('2026-11-16 16:05', 'Australia/Sydney'));
        $this->artisan('apq:sms:send-reminders')->expectsOutput('Sent 1 reminder(s).');
        $this->artisan('apq:sms:send-reminders')->expectsOutput('Sent 0 reminder(s).');

        $this->assertSame(1, SmsMessage::where('client_id', $this->client->id)->where('template_key', 'reminder')->count());
        $this->assertSame('skipped', SmsMessage::where('client_id', $optedOut->id)->sole()->status);
        $this->assertNotNull($tomorrow->fresh()->reminder_sent_at);

        Carbon::setTestNow(Carbon::parse('2026-11-16 20:30', 'Australia/Sydney'));   // quiet hours
        $this->visit('2026-11-17 13:00', Client::factory()->create(['mobile' => '+61444444444']));
        $this->artisan('apq:sms:send-reminders')->expectsOutput('Not reminder time yet.');
    }

    public function test_spanish_client_gets_spanish_reminder_and_manual_send_works(): void
    {
        $this->client->update(['preferred_language' => 'es', 'display_name' => 'María – Dee Why']);
        $visit = $this->visit('2026-11-17 09:00');

        $this->actingAs($this->admin)->post(route('admin.appointments.remind', $visit))->assertSessionHas('status');

        $msg = SmsMessage::sole();
        $this->assertSame('manual', $msg->trigger);
        $this->assertStringContainsString('Hola María', $msg->body);
        $this->assertStringContainsString('martes', $msg->body);
        $this->assertStringContainsString('09:00', $msg->body);
        $this->assertSame(__('sms.simulated'), $msg->status_detail);   // SMS_ENABLED=false
    }

    public function test_landline_or_missing_mobile_is_skipped(): void
    {
        $this->client->update(['mobile' => '+61299998888']);
        $visit = $this->visit('2026-11-17 09:00');

        $this->actingAs($this->admin)->post(route('admin.appointments.remind', $visit))->assertSessionHas('warning');
        $this->assertSame('skipped', SmsMessage::sole()->status);
    }

    // ---- Provider + webhooks ----

    public function test_mobile_message_gateway_matches_documented_api(): void
    {
        config(['mobilemessage.username' => 'u', 'mobilemessage.password' => 'p', 'mobilemessage.sender' => 'APQClean']);
        Http::fake(['api.mobilemessage.com.au/v1/messages' => Http::sequence()
            ->push(['status' => 'complete', 'total_cost' => 1, 'results' => [['to' => '61422222222', 'custom_ref' => 'r1', 'status' => 'success', 'message_id' => 'mm-1', 'cost' => 1]]])
            ->push(['status' => 'complete', 'results' => [['custom_ref' => 'r2', 'status' => 'error', 'error' => 'Invalid number']]])
            ->push(['status' => 'complete', 'results' => [['custom_ref' => 'r3', 'status' => 'blocked']]])
            ->push(['error' => 'Insufficient credits'], 403)
            ->push(['error' => 'Too many concurrent requests'], 429),
        ]);
        $gw = new MobileMessageGateway;

        $ok = $gw->send('+61422222222', 'Hello', 'r1');
        $this->assertTrue($ok['ok']);
        $this->assertSame('mm-1', $ok['provider_id']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mobilemessage.com.au/v1/messages'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('u:p'))
            && $r->header('Idempotency-Key')[0] === MobileMessageGateway::idempotencyKey('r1')
            && $r['messages'][0] === ['to' => '61422222222', 'message' => 'Hello', 'sender' => 'APQClean', 'custom_ref' => 'r1']);   // no "+"

        $this->assertSame('Invalid number', $gw->send('+61400000000', 'x', 'r2')['error']);
        $blocked = $gw->send('+61400000001', 'x', 'r3');
        $this->assertTrue($blocked['blocked']);
        $this->assertSame(__('sms.err_credits'), $gw->send('+61400000002', 'x', 'r4')['error']);
        $busy = $gw->send('+61400000003', 'x', 'r5');
        $this->assertTrue($busy['retryable']);
    }

    public function test_blocked_number_opts_the_client_out(): void
    {
        config(['mobilemessage.enabled' => true, 'mobilemessage.username' => 'u', 'mobilemessage.password' => 'p']);
        Http::fake(['api.mobilemessage.com.au/*' => Http::response(['status' => 'complete', 'results' => [['status' => 'blocked']]])]);
        $visit = $this->visit('2026-11-17 09:00');

        $this->actingAs($this->admin)->post(route('admin.appointments.remind', $visit));

        $this->assertSame('skipped', SmsMessage::sole()->status);
        $this->assertFalse($this->client->fresh()->sms_opt_in);
    }

    public function test_busy_provider_leaves_automatic_reminder_for_the_next_run(): void
    {
        config(['mobilemessage.enabled' => true, 'mobilemessage.username' => 'u', 'mobilemessage.password' => 'p']);
        Http::fakeSequence('api.mobilemessage.com.au/*')
            ->push(['error' => 'busy'], 429)
            ->push(['status' => 'complete', 'results' => [['status' => 'success', 'message_id' => 'mm-7', 'custom_ref' => 'x']]]);
        $this->visit('2026-11-17 09:00');
        Carbon::setTestNow(Carbon::parse('2026-11-16 16:05', 'Australia/Sydney'));

        $this->artisan('apq:sms:send-reminders')->expectsOutput('Sent 0 reminder(s).');
        $this->assertSame(0, SmsMessage::count());   // attempt forgotten, so it can be retried
        $this->artisan('apq:sms:send-reminders')->expectsOutput('Sent 1 reminder(s).');
        $this->assertSame('sent', SmsMessage::sole()->status);
    }

    public function test_landlines_and_overseas_numbers_are_not_texted(): void
    {
        $this->assertFalse(SmsDispatcher::textable('+61299998888'));
        $this->assertFalse(SmsDispatcher::textable('+447528389679'));
        $this->assertTrue(SmsDispatcher::textable('+61412345678'));
        $this->assertSame(1, SmsDispatcher::segments(str_repeat('a', 140).'{optout}'));   // {optout} counts as 20
        $this->assertSame(2, SmsDispatcher::segments(str_repeat('a', 141).'{optout}'));
    }

    public function test_webhook_signature_uses_documented_scheme(): void
    {
        // Test vector from the Mobile Message docs.
        $this->assertSame('52344b9592722e0241d82036e0920f4286bc0d47ba4624c5a1588193490a1efb', hash_hmac('sha256', '1754640000.{"test":1}', 'abc123'));

        config(['mobilemessage.webhook_token' => 'tok', 'mobilemessage.webhook_secret' => 'abc123']);
        $body = json_encode(['message_id' => 'mm-1', 'status' => 'delivered', 'part_number' => 1, 'total_parts' => 1]);
        $ts = (string) time();

        $this->call('POST', '/webhooks/mobile-message/tok/status', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
        $this->call('POST', '/webhooks/mobile-message/tok/status', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MM_TIMESTAMP' => $ts, 'HTTP_X_MM_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->call('POST', '/webhooks/mobile-message/tok/status', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MM_TIMESTAMP' => $ts, 'HTTP_X_MM_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, 'abc123')], $body)->assertOk();
    }

    public function test_multipart_receipts_never_turn_a_failure_into_delivered_and_unsubscribe_type_opts_out(): void
    {
        config(['mobilemessage.webhook_token' => 'tok']);
        $msg = SmsMessage::create(['client_id' => $this->client->id, 'to_number' => '+61422222222', 'body' => 'x', 'status' => 'sent', 'provider_message_id' => 'mm-2', 'custom_ref' => 'r2']);

        $this->postJson('/webhooks/mobile-message/tok/status', ['message_id' => 'mm-2', 'status' => 'failed', 'part_number' => 2, 'total_parts' => 2])->assertOk();
        $this->postJson('/webhooks/mobile-message/tok/status', ['message_id' => 'mm-2', 'status' => 'delivered', 'part_number' => 1, 'total_parts' => 2])->assertOk();
        $this->assertSame('failed', $msg->fresh()->status);

        $this->postJson('/webhooks/mobile-message/tok/inbound', ['type' => 'unsubscribe', 'sender' => '61422222222', 'to' => '61491570006', 'message' => 'opt out link', 'received_at' => '2026-11-16 00:00:00'])->assertOk();
        $this->assertFalse($this->client->fresh()->sms_opt_in);
    }

    public function test_check_account_shows_balance_and_flags_unapproved_sender(): void
    {
        config(['mobilemessage.username' => 'u', 'mobilemessage.password' => 'p', 'mobilemessage.sender' => 'APQClean']);
        Http::fake([
            'api.mobilemessage.com.au/v1/account' => Http::response(['status' => 'complete', 'credit_balance' => 1000]),
            'api.mobilemessage.com.au/v1/senders' => Http::response(['status' => 'complete', 'results' => [['sender' => 'CompanyABC', 'type' => 'alpha', 'label' => 'My Brand', 'is_default' => true]]]),
        ]);

        $this->actingAs($this->admin)->post(route('admin.sms.check'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1,000') && str_contains($s, 'CompanyABC'))
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'APQClean'));
    }

    public function test_test_recipient_redirects_outside_production(): void
    {
        config(['mobilemessage.test_recipient' => '+61499999999']);
        $sent = new \ArrayObject;
        $this->app->bind(SmsGateway::class, fn () => new class($sent) implements SmsGateway
        {
            public function __construct(private \ArrayObject $sent) {}

            public function send(string $to, string $body, string $customRef): array
            {
                $this->sent[] = $to;

                return ['ok' => true, 'provider_id' => 'x', 'cost' => 1.0, 'error' => null];
            }
        });
        $visit = $this->visit('2026-11-17 09:00');

        $this->actingAs($this->admin)->post(route('admin.appointments.remind', $visit));

        $this->assertSame(['+61499999999'], $sent->getArrayCopy());
        $this->assertStringContainsString('+61422222222', SmsMessage::sole()->status_detail);
    }

    public function test_webhooks_need_token_update_delivery_and_stop_opts_out(): void
    {
        config(['mobilemessage.webhook_token' => 'secret-token']);
        $msg = SmsMessage::create(['client_id' => $this->client->id, 'to_number' => '+61422222222', 'body' => 'x', 'status' => 'sent', 'provider_message_id' => 'mm-9', 'custom_ref' => 'r9']);

        $this->postJson('/webhooks/mobile-message/wrong/status', ['message_id' => 'mm-9', 'status' => 'delivered'])->assertNotFound();
        $this->postJson('/webhooks/mobile-message/secret-token/status', ['message_id' => 'mm-9', 'status' => 'delivered'])->assertOk();
        $this->assertSame('delivered', $msg->fresh()->status);
        $this->assertNotNull($msg->fresh()->delivered_at);

        $this->postJson('/webhooks/mobile-message/secret-token/inbound', ['type' => 'inbound', 'sender' => '61422222222', 'message' => 'Stop'])->assertOk();
        $this->assertFalse($this->client->fresh()->sms_opt_in);
        $this->assertSame('received', SmsMessage::where('direction', 'inbound')->sole()->status);
    }

    // ---- Admin pages ----

    public function test_admin_pages_and_template_validation(): void
    {
        $this->visit('2026-11-17 09:00');
        $this->actingAs($this->admin)->get(route('admin.cancellations.index'))->assertOk()->assertSee('Jo Smith')->assertSee('fee applies');
        $this->get(route('admin.sms.index'))->assertOk()->assertSee(__('sms.disabled_banner'));

        $t = SmsTemplate::where('key', 'reminder')->first();
        $this->put(route('admin.sms.templates.update', $t), ['body_en' => 'Hi {first_name} {oops}', 'body_es' => 'Hola'])->assertSessionHasErrors();
        $this->put(route('admin.sms.templates.update', $t), ['body_en' => 'Hi {first_name}, see you {day}', 'body_es' => 'Hola {first_name}'])->assertSessionHasNoErrors();
        $this->put(route('admin.sms.settings'), ['reminder_time' => '17:00', 'quiet_end' => '08:00', 'quiet_start' => '20:00', 'reminders_enabled' => '1'])->assertSessionHasNoErrors();

        $this->actingAs($this->cleaner)->get(route('admin.cancellations.index'))->assertForbidden();
        $this->actingAs($this->cleaner)->get(route('admin.sms.index'))->assertForbidden();
    }
}
