<?php

namespace Tests\Feature;

use App\Console\Commands\RestoreDatabase;
use App\Models\Client;
use App\Models\XeroConnection;
use App\Services\Backup\DatabaseBackup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Moving staging's data to the live site: backup → restore round trip (MariaDB only). */
class RestoreDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    public function test_restore_requires_force_and_round_trips_a_backup(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->artisan('apq:restore', ['file' => __FILE__, '--force' => true])->assertFailed();
            $this->markTestSkipped('Round trip runs on MariaDB (CI matrix).');
        }

        Client::factory()->count(3)->create(['admin_notes' => "line1\nline2; it's \"quoted\";"]);
        XeroConnection::create(['tenant_id' => 't', 'tenant_name' => 'x', 'connection_id' => 'c', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now(), 'is_active' => true]);
        $backup = app(DatabaseBackup::class)->run();
        $file = storage_path('app/private/'.$backup['path']);
        Client::query()->forceDelete();

        $this->artisan('apq:restore', ['file' => $file])->assertFailed();
        $this->assertSame(0, Client::count());

        $this->artisan('apq:restore', ['file' => $file, '--force' => true])->assertSuccessful();
        $this->assertSame(3, Client::count());
        $this->assertSame("line1\nline2; it's \"quoted\";", Client::first()->admin_notes);
        $this->assertSame(0, XeroConnection::count());
        @unlink($file);
    }

    public function test_xero_forget_removes_connection_locally(): void
    {
        XeroConnection::create(['tenant_id' => 't', 'tenant_name' => 'x', 'connection_id' => 'c', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now(), 'is_active' => true]);

        $this->artisan('apq:xero:forget')->assertSuccessful();

        $this->assertSame(0, XeroConnection::count());
    }

    public function test_dumps_from_newer_servers_are_made_compatible(): void
    {
        $restore = new RestoreDatabase;
        $sql = "CREATE TABLE `invoices` (\n  `id` bigint unsigned NOT NULL,\n  `idempotency_key` uuid NOT NULL,\n  `uuid` varchar(191) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";

        $old = $restore->compatible($sql, supportsUuid: false);
        $this->assertStringContainsString('`idempotency_key` char(36) NOT NULL', $old);
        $this->assertStringContainsString('`uuid` varchar(191)', $old);   // a column NAMED uuid is untouched
        $this->assertStringEndsWith('ROW_FORMAT=DYNAMIC;', $old);

        $this->assertStringContainsString('`idempotency_key` uuid NOT NULL', $restore->compatible($sql, supportsUuid: true));
        $this->assertSame("INSERT INTO `x` VALUES ('uuid');", $restore->compatible("INSERT INTO `x` VALUES ('uuid');", false));
    }
}
