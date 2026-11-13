<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'display_name' => 'Example – Manly', 'billing_name' => '', 'mobile' => '0412 000 111',
            'email' => 'example@example.com', 'address_line1' => '1 Example St', 'suburb' => 'Manly',
            'state' => 'NSW', 'postcode' => '2095', 'pricing_model' => 'fixed', 'fixed_price' => '280',
            'payment_method' => 'abn', 'preferred_language' => 'en', 'sms_opt_in' => 1, 'is_active' => 1,
            'access_notes' => 'Lockbox 1234',
        ];
    }

    public function test_admin_creates_client(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.clients.store'), $this->payload())->assertRedirect();

        $client = Client::firstOrFail();
        $this->assertSame('+61412000111', $client->mobile);
        $this->assertSame('Example – Manly', $client->billing_name);   // defaults to display name
        $this->assertSame(PaymentMethod::Abn, $client->payment_method);
        $this->assertEquals(280, $client->currentPrice());
    }

    public function test_fixed_price_required_for_fixed_pricing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.clients.store'), $this->payload(['fixed_price' => '']))
            ->assertSessionHasErrors('fixed_price');
    }

    public function test_index_search_and_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create(['display_name' => 'Findable – Curl Curl', 'suburb' => 'Curl Curl']);
        Client::factory()->create(['display_name' => 'Other – Manly']);

        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Curl']))
            ->assertOk()->assertSee('Findable')->assertDontSee('Other – Manly');
        $this->get(route('admin.clients.show', $client))->assertOk();
        $this->get(route('admin.clients.edit', $client))->assertOk();
        $this->get(route('admin.clients.create'))->assertOk();
        $this->get(route('admin.schedule'))->assertOk();
        $this->get(route('admin.appointments.create', ['client' => $client->id]))->assertOk();
    }

    public function test_staff_cannot_reach_client_admin(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.clients.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.clients.store'), $this->payload())->assertForbidden();
    }
}
