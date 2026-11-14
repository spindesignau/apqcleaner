<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xero_connections', function (Blueprint $table) {
            $table->id();
            $table->char('connection_id', 36)->nullable();   // Xero's id for this app↔org link (used to disconnect)
            $table->char('tenant_id', 36);
            $table->string('tenant_name')->nullable();
            $table->text('access_token');                     // encrypted cast
            $table->text('refresh_token');                    // encrypted cast
            $table->dateTime('expires_at');
            $table->string('scopes', 500)->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('mode', 12);                      // draft | authorised
            $table->boolean('send_email')->default(false);
            $table->unsignedInteger('invoices_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('service');  // service | cancellation_fee
            $table->string('reference', 60);
            $table->uuid('idempotency_key')->unique();
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('gst', 10, 2);
            $table->decimal('total', 10, 2);
            $table->string('mode', 12)->default('draft');    // what we asked Xero for
            $table->boolean('send_email')->default(false);
            $table->char('xero_invoice_id', 36)->nullable()->unique();
            $table->string('xero_invoice_number', 30)->nullable();
            $table->string('xero_status', 12)->nullable();   // DRAFT SUBMITTED AUTHORISED PAID VOIDED DELETED
            $table->decimal('xero_total', 10, 2)->nullable();
            $table->decimal('xero_amount_due', 10, 2)->nullable();
            $table->string('xero_online_url')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('sync_status', 10)->default('pending');   // pending pushing synced failed voided
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'issue_date']);
            $table->index(['sync_status']);
            $table->index(['xero_status']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 4000);
            $table->decimal('quantity', 10, 4);
            $table->decimal('unit_amount', 10, 2);
            $table->string('account_code', 10);
            $table->string('tax_type', 20)->default('OUTPUT');
            $table->decimal('line_amount', 10, 2);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->index('invoice_line_id');
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service', 20);                     // xero | mobile_message
            $table->string('direction', 10);                   // request | webhook
            $table->string('method', 8)->nullable();
            $table->string('endpoint', 255);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('summary', 500)->nullable();        // never tokens or full payloads
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropIndex(['invoice_line_id']));
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_runs');
        Schema::dropIfExists('xero_connections');
    }
};
