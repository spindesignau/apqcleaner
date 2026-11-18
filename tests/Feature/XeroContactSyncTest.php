<?php

namespace Tests\Feature;

use App\Jobs\PushClientToXero;
use App\Models\Client;
use App\Models\User;
use App\Models\XeroConnection;
use App\Services\Settings;
use App\Services\Xero\ContactMapper;
use App\Services\Xero\XeroContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class XeroContactSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xero.webhook_key' => 'whkey']);
        $this->admin = User::factory()->admin()->create();
        XeroConnection::create([
            'tenant_id' => 'tenant-1', 'tenant_name' => 'APQ Demo', 'connection_id' => 'conn-1',
            'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addMinutes(30), 'is_active' => true,
        ]);
    }

    private function contact(string $id, string $name, array $extra = []): array
    {
        return $extra + [
            'ContactID' => $id, 'ContactStatus' => 'ACTIVE', 'Name' => $name, 'IsCustomer' => true,
            'EmailAddress' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'Phones' => [['PhoneType' => 'MOBILE', 'PhoneNumber' => '0412 345 678'], ['PhoneType' => 'DEFAULT', 'PhoneAreaCode' => '02', 'PhoneNumber' => '9999 1111']],
            'Addresses' => [['AddressType' => 'POBOX', 'AddressLine1' => 'PO Box 1'], ['AddressType' => 'STREET', 'AddressLine1' => '5 Beach St', 'City' => 'Bondi', 'Region' => 'New South Wales', 'PostalCode' => '2026']],
            'UpdatedDateUTC' => '/Date(1763424000000+0000)/',
        ];
    }

    private function id(int $n): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $n);
    }

    public function test_mapper_reads_xero_contact(): void
    {
        $details = app(ContactMapper::class)->fromXero($this->contact($this->id(1), 'Jane Citizen'));

        $this->assertSame('Jane Citizen', $details['billing_name']);
        $this->assertSame('+61412345678', $details['mobile']);
        $this->assertSame('+61299991111', $details['phone_alt']);
        $this->assertSame('5 Beach St', $details['address_line1']);
        $this->assertSame(['Bondi', 'NSW', '2026'], [$details['suburb'], $details['state'], $details['postcode']]);
    }

    public function test_import_page_classifies_and_imports_new_and_links_matches(): void
    {
        Queue::fake();
        $existing = Client::factory()->create(['billing_name' => 'Old Name', 'email' => 'match.me@example.com', 'mobile' => null, 'access_notes' => 'Key under mat']);
        $linked = Client::factory()->create();
        $linked->forceFill(['xero_contact_id' => $this->id(3)])->saveQuietly();

        Http::fake(['api.xero.com/api.xro/2.0/Contacts*' => Http::sequence()
            ->push(['Contacts' => [
                $this->contact($this->id(1), 'Brand New'),
                $this->contact($this->id(2), 'Match Me', ['EmailAddress' => 'Match.Me@example.com', 'Phones' => []]),
                $this->contact($this->id(3), 'Already Linked'),
                $this->contact($this->id(4), 'A Supplier', ['IsCustomer' => false]),
                $this->contact($this->id(5), 'Archived', ['ContactStatus' => 'ARCHIVED']),
            ]])
            ->push(['Contacts' => []]),
        ]);

        $this->actingAs($this->admin)->get(route('admin.xero.contacts'))->assertOk()
            ->assertSee('Brand New')->assertSee('Match Me')->assertSee('Already Linked')
            ->assertDontSee('A Supplier')->assertDontSee('Archived');
        $this->get(route('admin.xero.contacts', ['show' => 'all']))->assertSee('A Supplier');

        $this->post(route('admin.xero.contacts.import'), ['contacts' => [$this->id(1), $this->id(2), $this->id(3)]])
            ->assertSessionHas('status', __('xero.import_done', ['imported' => 1, 'linked' => 1]));

        $new = Client::where('xero_contact_id', $this->id(1))->firstOrFail();
        $this->assertSame(['Brand New – Bondi', 'Brand New', '5 Beach St', '+61412345678'], [$new->display_name, $new->billing_name, $new->address_line1, $new->mobile]);
        $this->assertNotNull($new->xero_hash);

        $existing->refresh();
        $this->assertSame($this->id(2), $existing->xero_contact_id);
        $this->assertSame('Match Me', $existing->billing_name);         // Xero wins…
        $this->assertSame('Key under mat', $existing->access_notes);    // …APQ-only fields untouched
        $this->assertSame(1, Client::where('xero_contact_id', $this->id(3))->count());
        Http::assertSentCount(2);   // the list was cached between the page and the import
    }

    public function test_editing_a_linked_client_pushes_to_xero(): void
    {
        $client = Client::factory()->create();
        app(XeroContacts::class)->link($client, $this->contact($this->id(7), 'Jane Citizen'), pushDifferences: false);
        Http::fake(['api.xero.com/api.xro/2.0/Contacts' => Http::response(['Contacts' => [$this->contact($this->id(7), 'Jane Smith')]])]);

        $client->refresh()->update(['billing_name' => 'Jane Smith', 'admin_notes' => 'x']);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['Contacts'][0]['ContactID'] === $this->id(7) && $r['Contacts'][0]['Name'] === 'Jane Smith');
        $this->assertFalse(app(XeroContacts::class)->hasUnsentEdits($client->refresh()));
        $this->assertNull($client->xero_sync_error);
    }

    public function test_unlinked_clients_and_non_contact_edits_are_not_pushed(): void
    {
        Queue::fake();
        $client = Client::factory()->create();
        $client->update(['billing_name' => 'Changed']);
        $client->forceFill(['xero_contact_id' => $this->id(8)])->saveQuietly();
        $client->update(['admin_notes' => 'only APQ']);

        Queue::assertNotPushed(PushClientToXero::class);
    }

    public function test_push_validation_error_is_shown_on_client(): void
    {
        $client = Client::factory()->create();
        $client->forceFill(['xero_contact_id' => $this->id(9)])->saveQuietly();
        Http::fake(['api.xero.com/api.xro/2.0/Contacts' => Http::response(['Elements' => [['ValidationErrors' => [['Message' => 'The contact name Jane is already assigned to another contact.']]]]], 400)]);

        PushClientToXero::dispatchSync($client->id);

        $this->assertStringContainsString('already assigned', $client->refresh()->xero_sync_error);
        $this->actingAs($this->admin)->get(route('admin.clients.show', $client))->assertSee('already assigned');
    }

    public function test_webhook_pulls_xero_changes_but_unsent_apq_edits_win(): void
    {
        $client = Client::factory()->create(['access_notes' => 'Gate 1234']);
        app(XeroContacts::class)->link($client, $this->contact($this->id(10), 'Jane Citizen'), pushDifferences: false);
        Http::fake(['api.xero.com/api.xro/2.0/Contacts/*' => Http::response(['Contacts' => [$this->contact($this->id(10), 'Jane Citizen', ['EmailAddress' => 'new@example.com'])]])]);

        $body = json_encode(['events' => [['tenantId' => 'tenant-1', 'eventCategory' => 'CONTACT', 'eventType' => 'UPDATE', 'resourceId' => $this->id(10)]]]);
        $this->call('POST', '/webhooks/xero', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XERO_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'whkey', true))], $body)->assertOk();

        $client->refresh();
        $this->assertSame('new@example.com', $client->email);
        $this->assertSame('Gate 1234', $client->access_notes);

        // An APQ edit that hasn't reached Xero yet is pushed instead of being overwritten.
        Queue::fake();
        $client->forceFill(['email' => 'apq@example.com'])->saveQuietly();
        app(XeroContacts::class)->pull($client, $this->contact($this->id(10), 'Jane Citizen'));
        $this->assertSame('apq@example.com', $client->refresh()->email);
        Queue::assertPushed(PushClientToXero::class);
    }

    public function test_sync_command_pulls_changed_contacts(): void
    {
        $client = Client::factory()->create();
        app(XeroContacts::class)->link($client, $this->contact($this->id(11), 'Jane Citizen'), pushDifferences: false);
        Http::fake(['api.xero.com/api.xro/2.0/Contacts*' => Http::sequence()
            ->push(['Contacts' => [$this->contact($this->id(11), 'Jane Renamed'), $this->contact($this->id(12), 'Not Ours')]])
            ->push(['Contacts' => []]),
        ]);

        $this->artisan('apq:xero:sync-contacts')->assertSuccessful();

        $this->assertSame('Jane Renamed', $client->refresh()->billing_name);
        $this->assertSame(0, Client::where('xero_contact_id', $this->id(12))->count());
        $this->assertNotNull(app(Settings::class)->get('xero.contacts_synced_at'));
    }
}
