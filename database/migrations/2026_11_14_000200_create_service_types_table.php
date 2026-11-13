<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 100);
            $table->string('name_es', 100);
            $table->decimal('default_hourly_rate', 8, 2)->nullable();
            $table->decimal('default_fixed_price', 10, 2)->nullable();
            $table->string('xero_item_code', 30)->nullable();
            $table->string('xero_account_code', 10)->default('200');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('service_types')->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], [
            ['name_en' => 'General Service', 'name_es' => 'Servicio general', 'sort_order' => 1],
            ['name_en' => 'First General Service', 'name_es' => 'Primer servicio general', 'sort_order' => 2],
            ['name_en' => 'Deep Clean', 'name_es' => 'Limpieza profunda', 'sort_order' => 3],
            ['name_en' => 'End of Lease / End of Work', 'name_es' => 'Fin de contrato / fin de obra', 'sort_order' => 4],
            ['name_en' => 'Airbnb Turnover', 'name_es' => 'Cambio Airbnb', 'sort_order' => 5],
            ['name_en' => 'Housekeeping', 'name_es' => 'Mantenimiento del hogar', 'sort_order' => 6],
            ['name_en' => 'Windows', 'name_es' => 'Ventanas', 'sort_order' => 7],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
