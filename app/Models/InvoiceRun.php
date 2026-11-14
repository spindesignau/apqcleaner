<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'send_email' => 'boolean'];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
