<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportClientsTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = base_path('tests/fixtures/clients.csv');
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->artisan('apq:import:clients', ['file' => $this->file])->assertSuccessful();
        $this->assertSame(0, Client::count());
    }

    public function test_import_skips_cancelled_and_duplicates(): void
    {
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true])->assertSuccessful();

        $this->assertSame(1, Client::count());   // two is cancelled, three shares one's phone
        $c = Client::firstOrFail();
        $this->assertSame('Test Person One', $c->display_name);
        $this->assertSame('+61412000001', $c->mobile);
        $this->assertSame('1 Test St', $c->address_line1);
        $this->assertSame('Manly', $c->suburb);
        $this->assertSame('2095', $c->postcode);
        $this->assertSame('Key under mat', $c->access_notes);
        $this->assertSame('Oven please', $c->default_job_notes);
        $this->assertSame('abn', $c->payment_method->value);
        $this->assertSame('Fortnightly', $c->usual_frequency);
        $this->assertTrue($c->is_active);
    }

    public function test_include_cancelled_imports_them_inactive(): void
    {
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true, '--include-cancelled' => true])->assertSuccessful();

        $two = Client::where('display_name', 'Test Person Two')->firstOrFail();
        $this->assertFalse($two->is_active);
        $this->assertSame('Fairlight', $two->suburb);
        $this->assertNull($two->email);
        $this->assertStringContainsString('not-an-email', $two->admin_notes);
        $this->assertStringContainsString('Gifts: NAVIDAD', $two->admin_notes);
    }
}
