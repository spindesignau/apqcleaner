<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cancellation extends Model
{
    public const CLIENT_REASONS = ['client_cancelled', 'client_no_access'];

    public const REASONS = ['client_cancelled', 'client_no_access', 'weather', 'staff_unavailable', 'other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['notice_given_at' => 'datetime', 'fee_eligible' => 'boolean', 'fee_amount_ex_gst' => 'decimal:2', 'hours_notice' => 'decimal:2'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
