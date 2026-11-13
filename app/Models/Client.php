<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PricingModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    /** Admin-only model: every write goes through an admin Form Request. */
    protected $guarded = ['id', 'xero_contact_id', 'xero_synced_at', 'sms_opted_out_at'];

    protected function casts(): array
    {
        return [
            'pricing_model' => PricingModel::class,
            'payment_method' => PaymentMethod::class,
            'fixed_price' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'cancellation_fee_value' => 'decimal:2',
            'sms_opt_in' => 'boolean',
            'show_phone_to_staff' => 'boolean',
            'is_active' => 'boolean',
            'sms_opted_out_at' => 'datetime',
            'xero_synced_at' => 'datetime',
        ];
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function schedules()
    {
        return $this->hasMany(RecurringSchedule::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function fullAddress(): string
    {
        return collect([$this->address_line1, $this->address_line2, trim("{$this->suburb} {$this->state} {$this->postcode}")])
            ->filter()->implode(', ');
    }

    public function mapUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->fullAddress());
    }

    /** The price recorded on a new visit: fixed price, or hourly rate for hourly clients. */
    public function currentPrice(): ?string
    {
        return $this->pricing_model === PricingModel::Hourly
            ? ($this->hourly_rate ?? $this->serviceType?->default_hourly_rate)
            : ($this->fixed_price ?? $this->serviceType?->default_fixed_price);
    }
}
