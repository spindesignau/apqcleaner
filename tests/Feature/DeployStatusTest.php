<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeployStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('app/deploy-status.json'));
        parent::tearDown();
    }

    public function test_pull_deploy_status_shows_on_settings(): void
    {
        $this->assertNotContains('deploy', collect(app(SystemStatus::class)->checks())->pluck('key'));

        file_put_contents(storage_path('app/deploy-status.json'), json_encode(['state' => 'failed', 'release' => 'live-2', 'message' => 'Release live-3 failed and was rolled back: health check returned HTTP 500', 'at' => now()->toIso8601String()]));
        $check = collect(app(SystemStatus::class)->checks())->firstWhere('key', 'deploy');
        $this->assertFalse($check['ok']);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.settings'))->assertOk()->assertSee('rolled back');
    }
}
