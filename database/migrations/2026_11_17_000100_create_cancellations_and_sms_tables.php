<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('reason', 30);                     // client_cancelled client_no_access weather staff_unavailable other
            $table->string('reason_note')->nullable();
            $table->dateTime('notice_given_at');              // UTC
            $table->decimal('hours_notice', 7, 2);
            $table->boolean('fee_eligible');
            $table->string('fee_decision', 20);               // charged waived not_applicable
            $table->decimal('fee_amount_ex_gst', 10, 2)->nullable();
            $table->string('waive_reason')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->text('body_en');
            $table->text('body_es');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 10)->default('outbound');   // outbound | inbound
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // staff recipient
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 50)->nullable();
            $table->string('trigger', 10)->default('manual');        // auto | manual | system
            $table->string('to_number', 20)->nullable();
            $table->string('from_number', 20)->nullable();
            $table->text('body');
            $table->unsignedTinyInteger('segments')->nullable();
            $table->string('provider_message_id', 64)->nullable()->index();
            $table->string('custom_ref', 64)->nullable()->unique();
            $table->string('status', 12)->default('queued');         // queued sent delivered failed skipped received
            $table->string('status_detail')->nullable();
            $table->decimal('cost_credits', 8, 2)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        $now = now();
        DB::table('sms_templates')->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], [
            ['key' => 'reminder', 'body_en' => 'Hi {first_name}, reminder from APQ Cleaning Service: your clean is {day} {date} at {time}. Need to change? Call {business_phone}. Reply STOP to opt out', 'body_es' => 'Hola {first_name}, recordatorio de APQ Cleaning Service: su limpieza es el {day} {date} a las {time}. ¿Cambios? Llame al {business_phone}. Responda STOP para no recibir más'],
            ['key' => 'cancellation', 'body_en' => 'Hi {first_name}, your APQ Cleaning Service clean on {day} {date} at {time} has been cancelled. Questions? {business_phone}', 'body_es' => 'Hola {first_name}, su limpieza de APQ Cleaning Service del {day} {date} a las {time} fue cancelada. ¿Preguntas? {business_phone}'],
            ['key' => 'staff_cancelled', 'body_en' => 'APQ: Job cancelled – {client}, {day} {date} {time}.', 'body_es' => 'APQ: Trabajo cancelado – {client}, {day} {date} {time}.'],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('cancellations');
    }
};
