<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('display_name', 150);              // shown to staff, e.g. "Smith – Manly"
            $table->string('billing_name', 150);              // Xero contact name
            $table->string('email', 190)->nullable();
            $table->string('mobile', 20)->nullable();         // E.164
            $table->string('phone_alt', 30)->nullable();

            // Service address (where the clean happens)
            $table->string('address_line1', 190);
            $table->string('address_line2', 190)->nullable();
            $table->string('suburb', 80)->nullable();
            $table->string('state', 3)->default('NSW');
            $table->string('postcode', 4)->nullable();
            $table->string('billing_address', 255)->nullable(); // only when different

            $table->text('access_notes')->nullable();          // keys, codes — visible to assigned staff
            $table->text('default_job_notes')->nullable();     // "focus on" — visible to assigned staff
            $table->text('admin_notes')->nullable();           // admin only

            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pricing_model', 10)->default('fixed');
            $table->decimal('fixed_price', 10, 2)->nullable();
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->smallInteger('billing_increment_minutes')->default(15);
            $table->smallInteger('minimum_billable_minutes')->default(0);
            $table->string('payment_method', 10)->default('cash');   // cash | abn — all clients are invoiced
            $table->string('invoice_frequency', 12)->default('monthly');

            $table->smallInteger('cancellation_window_hours')->default(24);
            $table->string('cancellation_fee_type', 20)->default('percent_of_visit');
            $table->decimal('cancellation_fee_value', 8, 2)->default(50);

            $table->string('usual_frequency', 40)->nullable();       // informational, from the old spreadsheet
            $table->string('referral_source', 150)->nullable();
            $table->char('preferred_language', 2)->default('en');
            $table->boolean('sms_opt_in')->default(true);
            $table->timestamp('sms_opted_out_at')->nullable();
            $table->boolean('show_phone_to_staff')->default(false);
            $table->char('xero_contact_id', 36)->nullable()->unique();
            $table->timestamp('xero_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'display_name']);
            $table->index('mobile');
            $table->index('email');
            $table->index('suburb');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
