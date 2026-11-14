<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'cost_credits' => 'decimal:2'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function badgeClass(): string
    {
        return match ($this->status) {
            'delivered' => 'badge-navy',
            'sent' => 'badge-sage',
            'queued' => 'badge-amber',
            'failed' => 'badge-alert',
            'received' => 'badge-sage',
            default => 'badge-sand',
        };
    }
}
