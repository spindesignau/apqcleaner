<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();   // denormalised for reporting
            $table->string('status', 12)->default('running')->index();
            // = user_id while running, NULL otherwise. The unique index guarantees one running timer per cleaner.
            $table->unsignedBigInteger('running_lock')->nullable()->unique();
            $table->dateTime('started_at');                                       // UTC, server clock
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('billable_minutes')->nullable();
            $table->text('staff_notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->dateTime('original_started_at')->nullable();
            $table->dateTime('original_ended_at')->nullable();
            $table->boolean('staff_adjusted')->default(false);
            $table->boolean('admin_adjusted')->default(false);
            $table->boolean('auto_stopped')->default(false);
            foreach (['start_lat', 'start_lng', 'end_lat', 'end_lng'] as $col) {
                $table->decimal($col, 10, 7)->nullable();
            }
            $table->decimal('charge_rate', 10, 2)->nullable();   // snapshot at approval: visit price or hourly rate
            $table->decimal('pay_rate', 8, 2)->nullable();       // snapshot at approval (weekend rates applied)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejected_reason')->nullable();
            $table->unsignedBigInteger('invoice_line_id')->nullable();   // FK added with invoices in Phase 3
            $table->boolean('is_legacy')->default(false);
            $table->timestamps();

            $table->unique(['appointment_id', 'user_id']);
            $table->index(['user_id', 'started_at']);
            $table->index(['client_id', 'status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
