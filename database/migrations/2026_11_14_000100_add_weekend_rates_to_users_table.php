<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Optional weekend pay rates; null = use hourly_rate.
            $table->decimal('saturday_rate', 8, 2)->nullable()->after('hourly_rate');
            $table->decimal('sunday_rate', 8, 2)->nullable()->after('saturday_rate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['saturday_rate', 'sunday_rate']);
        });
    }
};
