<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'default_hourly_rate' => 'decimal:2', 'default_fixed_price' => 'decimal:2'];
    }

    public function name(): string
    {
        return app()->getLocale() === 'es' ? $this->name_es : $this->name_en;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
