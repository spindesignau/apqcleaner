<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->char('xero_hash', 64)->nullable()->after('xero_synced_at');      // contact details as last agreed with Xero
            $table->timestamp('xero_updated_at')->nullable()->after('xero_hash');     // Xero's UpdatedDateUTC we last saw
            $table->string('xero_sync_error', 255)->nullable()->after('xero_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['xero_hash', 'xero_updated_at', 'xero_sync_error']);
        });
    }
};
