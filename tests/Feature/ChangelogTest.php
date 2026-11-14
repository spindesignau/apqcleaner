<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_changelog_link_and_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.changelog'))->assertSee("What's new");
        $this->get(route('admin.changelog'))->assertOk()->assertSee('Xero invoicing');
    }

    public function test_staff_never_see_the_changelog(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get(route('admin.changelog'))->assertForbidden();
        foreach (['jobs.today', 'jobs.week', 'profile.edit'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee(route('admin.changelog'))->assertDontSee("What's new");
        }
    }

    public function test_login_page_has_no_changelog(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('changelog')->assertDontSee("What's new");
        $this->get('/admin/changelog')->assertRedirect(route('login'));
    }
}
