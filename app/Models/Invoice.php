<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const SYNC_PENDING = 'pending';

    public const SYNC_PUSHING = 'pushing';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_FAILED = 'failed';

    public const SYNC_VOIDED = 'voided';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'subtotal' => 'decimal:2',
            'gst' => 'decimal:2',
            'total' => 'decimal:2',
            'xero_total' => 'decimal:2',
            'xero_amount_due' => 'decimal:2',
            'send_email' => 'boolean',
            'emailed_at' => 'datetime',
            'paid_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort');
    }

    public function run()
    {
        return $this->belongsTo(InvoiceRun::class, 'invoice_run_id');
    }

    public function isOpenInXero(): bool
    {
        return $this->xero_invoice_id && in_array($this->xero_status, ['DRAFT', 'SUBMITTED', 'AUTHORISED'], true);
    }

    public function xeroUrl(): ?string
    {
        return $this->xero_invoice_id ? config('xero.invoice_url').$this->xero_invoice_id : null;
    }

    /** One label for the list: where is this invoice up to? */
    public function stateKey(): string
    {
        return match (true) {
            $this->sync_status === self::SYNC_VOIDED => 'voided',
            $this->sync_status === self::SYNC_FAILED => 'failed',
            in_array($this->sync_status, [self::SYNC_PENDING, self::SYNC_PUSHING], true) => 'sending',
            $this->xero_status === 'PAID' => 'paid',
            $this->xero_status === 'AUTHORISED' && $this->emailed_at => 'sent',
            $this->xero_status === 'AUTHORISED' => 'approved',
            default => 'draft',
        };
    }

    public function stateBadge(): string
    {
        return match ($this->stateKey()) {
            'paid' => 'badge-navy',
            'sent', 'approved' => 'badge-sage',
            'failed' => 'badge-alert',
            'sending' => 'badge-amber',
            'voided' => 'badge-sand line-through',
            default => 'badge-sand',
        };
    }
}
