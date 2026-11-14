<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\EmploymentType;
use App\Enums\PricingModel;
use App\Enums\TimeEntryStatus;
use App\Models\Appointment;
use App\Models\Cancellation;
use App\Models\Client;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use App\Services\Reports\Reports;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsAndSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rosa;

    private User $sam;

    private Client $fixed;

    private Client $hourly;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-02 10:00', 'Australia/Sydney'));
        $this->admin = User::factory()->admin()->create();
        $this->rosa = User::factory()->create(['name' => 'Rosa Example', 'hourly_rate' => 35, 'saturday_rate' => 42, 'employment_type' => EmploymentType::Casual]);
        $this->sam = User::factory()->create(['name' => 'Sam Contractor', 'hourly_rate' => 40, 'employment_type' => EmploymentType::ContractorAbn]);
        $this->fixed = Client::factory()->create(['display_name' => 'Fixed Example', 'fixed_price' => 300]);
        $this->hourly = Client::factory()->create(['display_name' => 'Hourly Example', 'pricing_model' => PricingModel::Hourly, 'hourly_rate' => 60, 'fixed_price' => null]);
    }

    private function work(Client $client, User $cleaner, string $localStart, int $scheduled, int $minutes, ?Appointment $visit = null): Appointment
    {
        $visit ??= Appointment::factory()->for($client)->at($localStart, $scheduled)->create([
            'pricing_model' => $client->pricing_model, 'price' => $client->currentPrice(), 'status' => AppointmentStatus::Completed,
        ]);
        $visit->staff()->syncWithoutDetaching([$cleaner->id]);
        $start = Carbon::parse($localStart, 'Australia/Sydney');
        TimeEntry::factory()->for($visit)->for($cleaner)->create([
            'client_id' => $client->id, 'status' => TimeEntryStatus::Approved, 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes($minutes),
            'duration_minutes' => $minutes, 'billable_minutes' => $minutes, 'charge_rate' => $visit->price,
            'pay_rate' => $cleaner->payRateFor($start),
        ]);

        return $visit;
    }

    private function november(): array
    {
        return [Carbon::parse('2026-11-01', 'Australia/Sydney')->startOfDay(), Carbon::parse('2026-11-30', 'Australia/Sydney')->endOfDay()];
    }

    private function seedWork(): void
    {
        $v = $this->work($this->fixed, $this->rosa, '2026-11-03 09:00', 180, 200);       // Tue: fixed $300, two cleaners
        $this->work($this->fixed, $this->sam, '2026-11-03 09:00', 180, 180, $v);
        $this->work($this->hourly, $this->rosa, '2026-11-07 09:00', 120, 150);          // Saturday: 2.5 h × $60 = $150
        $cancelled = Appointment::factory()->for($this->fixed)->at('2026-11-10 09:00', 180)->create(['status' => AppointmentStatus::CancelledLate]);
        Cancellation::create(['appointment_id' => $cancelled->id, 'reason' => 'client_cancelled', 'notice_given_at' => now(), 'hours_notice' => 5,
            'fee_eligible' => true, 'fee_decision' => 'charged', 'fee_amount_ex_gst' => 150]);
    }

    public function test_revenue_report_prices_like_invoices_and_includes_fees(): void
    {
        $this->seedWork();
        [$from, $to] = $this->november();
        $r = app(Reports::class)->run('revenue', $from, $to);

        $fixed = collect($r['rows'])->firstWhere('client', 'Fixed Example');
        $this->assertSame(1, $fixed['visits']);
        $this->assertEquals(300, $fixed['revenue']);   // once per visit, not per cleaner
        $this->assertEquals(150, $fixed['fees']);
        $this->assertEquals(450, $fixed['total_ex']);
        $this->assertEquals(495, $fixed['total_inc']);
        $this->assertEquals(150, collect($r['rows'])->firstWhere('client', 'Hourly Example')['revenue']);
        $this->assertEquals(600, $r['totals']['total_ex']);
    }

    public function test_payroll_splits_weekend_hours_uses_locked_rates_and_on_costs(): void
    {
        $this->seedWork();
        [$from, $to] = $this->november();
        $rows = collect(app(Reports::class)->run('payroll', $from, $to)['rows']);

        $rosa = $rows->firstWhere('staff', 'Rosa Example');
        $this->assertSame(200, $rosa['weekday']);
        $this->assertSame(150, $rosa['saturday']);
        $this->assertEquals(round(200 / 60 * 35 + 150 / 60 * 42, 2), $rosa['pay']);   // 116.67 + 105.00
        $this->assertEquals(round($rosa['pay'] * 0.12, 2), $rosa['oncost']);
        $this->assertEquals(0, $rows->firstWhere('staff', 'Sam Contractor')['oncost']);   // ABN contractor

        $detail = app(Reports::class)->run('payroll', $from, $to, null, $this->rosa->id, true);
        $this->assertCount(2, $detail['rows']);
        $this->assertEquals(42, collect($detail['rows'])->firstWhere('date', '2026-11-07')['rate']);
    }

    public function test_margin_and_variance(): void
    {
        $this->seedWork();
        [$from, $to] = $this->november();
        $margin = collect(app(Reports::class)->run('margin', $from, $to)['rows'])->firstWhere('client', 'Fixed Example');
        $labour = round(200 / 60 * 35 * 1.12 + 180 / 60 * 40, 2);
        $this->assertEqualsWithDelta($labour, $margin['labour'], 0.02);
        $this->assertEqualsWithDelta(300 - $labour, $margin['margin'], 0.02);

        $variance = collect(app(Reports::class)->run('variance', $from, $to)['rows'])->firstWhere('client', 'Hourly Example');
        $this->assertSame(30, $variance['avg_diff']);   // 150 actual vs 120 scheduled
        $this->assertSame(1, $variance['over_30']);
    }

    public function test_report_pages_and_csv_export(): void
    {
        $this->seedWork();
        $this->actingAs($this->admin)->get(route('admin.reports.index'))->assertOk()->assertSee(__('reports.names.margin'));
        foreach (Reports::KEYS as $key) {
            $this->get(route('admin.reports.show', [$key, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk();
        }
        $csv = $this->get(route('admin.reports.show', ['payroll', 'from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'csv']))
            ->assertOk()->assertDownload()->streamedContent();
        $this->assertStringContainsString('"Rosa Example",3.33,2.50,0.00,5.83,221.67,26.60,248.27', $csv);   // hours as plain decimals

        $this->get(route('admin.reports.show', 'nope'))->assertNotFound();
        $this->actingAs($this->rosa)->get(route('admin.reports.index'))->assertForbidden();
    }

    public function test_backup_create_list_download_and_prune(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->post(route('admin.settings.backup'))->assertSessionHas('status');

        $list = app(DatabaseBackup::class)->list();
        $this->assertCount(1, $list);
        $this->assertStringEndsWith('.gz', $list[0]['name']);
        $sql = gzdecode(Storage::disk('local')->get('backups/'.$list[0]['name']));
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO', $sql);

        $this->get(route('admin.settings.backup.download', $list[0]['name']))->assertOk()->assertDownload($list[0]['name']);
        $this->get(route('admin.settings.backup.download', '../.env'))->assertNotFound();
        $this->actingAs($this->rosa)->get(route('admin.settings.backup.download', $list[0]['name']))->assertForbidden();

        Storage::disk('local')->put('backups/apq-db-2026-01-01_000000.sql.gz', 'old');
        touch(Storage::disk('local')->path('backups/apq-db-2026-01-01_000000.sql.gz'), now()->subDays(40)->getTimestamp());
        $this->assertSame(1, app(DatabaseBackup::class)->prune());
        $this->artisan('apq:backup')->assertSuccessful();
        $this->assertCount(2, app(DatabaseBackup::class)->list());
    }

    public function test_system_status_warns_when_cron_stops(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee(__('system.never_run'));
        $this->get(route('admin.dashboard'))->assertSee(__('system.checks.scheduler'));

        app(Settings::class)->set('system.scheduler_heartbeat', now()->subMinute()->toIso8601String());
        $this->get(route('admin.dashboard'))->assertDontSee(__('system.banner', ['list' => __('system.checks.scheduler')]));
        $this->get(route('admin.settings'))->assertOk()->assertDontSee(__('system.never_run'));
    }
}
