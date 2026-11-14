<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class XeroConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        return static::where('is_active', true)->latest('id')->first();
    }

    public function connectedBy()
    {
        return $this->belongsTo(User::class, 'connected_by');
    }
}
