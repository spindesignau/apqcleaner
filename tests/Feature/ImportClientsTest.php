<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_default_skips_cancelled_and_keeps_shared_phone_clients(): void
    {
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true])->assertSuccessful();

        // Row two is cancelled; row three shares row one's phone but is a different client, so it is kept.
        $this->assertSame(['Test Person One', 'Test Person Three'], Client::orderBy('display_name')->pluck('display_name')->all());
        $c = Client::where('display_name', 'Test Person One')->sole();
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

    public function test_cancelled_rows_can_be_imported_as_active_or_inactive(): void
    {
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true, '--cancelled' => 'active'])->assertSuccessful();
        $two = Client::where('display_name', 'Test Person Two')->sole();
        $this->assertTrue($two->is_active);
        $this->assertSame('Fairlight', $two->suburb);
        $this->assertNull($two->email);
        $this->assertStringContainsString('not-an-email', $two->admin_notes);
        $this->assertStringContainsString('Gifts: NAVIDAD', $two->admin_notes);

        Client::query()->forceDelete();
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true, '--include-cancelled' => true])->assertSuccessful();
        $this->assertFalse(Client::where('display_name', 'Test Person Two')->sole()->is_active);
    }

    public function test_reimport_skips_clients_already_in_the_app(): void
    {
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true, '--cancelled' => 'active']);
        $this->artisan('apq:import:clients', ['file' => $this->file, '--commit' => true, '--cancelled' => 'active']);

        $this->assertSame(3, Client::count());
    }

    public function test_admin_imports_through_the_browser_with_preview(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $upload = UploadedFile::fake()->createWithContent('clients.csv', file_get_contents($this->file));

        $this->actingAs($admin)->get(route('admin.clients.import'))->assertOk();
        $this->post(route('admin.clients.import.upload'), ['file' => $upload, 'cancelled' => 'active'])
            ->assertRedirect(route('admin.clients.import.preview'));

        $this->get(route('admin.clients.import.preview'))->assertOk()
            ->assertSee('Test Person Two')->assertSee(__('import.shares_mobile', ['name' => 'Test Person One']));
        $this->assertSame(0, Client::count());   // preview saves nothing

        $this->post(route('admin.clients.import.confirm'))->assertRedirect()
            ->assertSessionHas('status', trans_choice('import.done', 3, ['count' => 3]));
        $this->assertSame(3, Client::count());
        $this->assertSame([], Storage::disk('local')->allFiles('imports'));   // uploaded file removed
    }

    public function test_wrong_file_is_rejected_and_staff_are_blocked(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $bad = UploadedFile::fake()->createWithContent('other.csv', "a,b\n1,2\n");

        $this->actingAs($admin)->post(route('admin.clients.import.upload'), ['file' => $bad, 'cancelled' => 'active']);
        $this->get(route('admin.clients.import.preview'))->assertRedirect(route('admin.clients.import'));

        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('admin.clients.import'))->assertForbidden();
    }
}
