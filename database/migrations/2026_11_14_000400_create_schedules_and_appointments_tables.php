<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency', 30);
            $table->tinyInteger('weekday');                    // ISO 1 = Mon … 7 = Sun
            $table->tinyInteger('nth_week')->nullable();       // monthly_nth_weekday: 1–4, -1 = last
            $table->time('start_time');                        // local Sydney wall-clock
            $table->smallInteger('duration_minutes');
            $table->date('anchor_date');                       // first occurrence (sets fortnight parity)
            $table->date('effective_until')->nullable();
            $table->text('default_job_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recurring_schedule_staff', function (Blueprint $table) {
            $table->foreignId('recurring_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['recurring_schedule_id', 'user_id']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('recurring_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('occurrence_date')->nullable();
            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_start');               // UTC
            $table->dateTime('scheduled_end');                 // UTC
            $table->string('status', 20)->default('scheduled');
            $table->text('job_notes')->nullable();
            // Price snapshot at creation, so later rate changes don't alter past visits.
            $table->string('pricing_model', 10)->default('fixed');
            $table->decimal('price', 10, 2)->nullable();       // fixed price, or hourly rate when hourly
            $table->boolean('is_detached')->default(false);    // edited by hand: generator leaves it alone
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['recurring_schedule_id', 'occurrence_date']);
            $table->index(['scheduled_start', 'status']);
            $table->index(['client_id', 'scheduled_start']);
        });

        Schema::create('appointment_staff', function (Blueprint $table) {
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->primary(['appointment_id', 'user_id']);
            $table->index(['user_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_staff');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('recurring_schedule_staff');
        Schema::dropIfExists('recurring_schedules');
    }
};
