<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Only fields a staff member may change about themselves.
     * Admin-only fields (role, is_active, hourly_rate, employment_type, notes, …)
     * are written explicitly with forceFill() from admin-only requests.
     */
    protected $fillable = ['name', 'email', 'password', 'avatar_path', 'locale'];

    protected $hidden = ['password', 'remember_token', 'hourly_rate', 'notes', 'abn'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'employment_type' => EmploymentType::class,
            'hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'locked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? URL::temporarySignedRoute('avatar', now()->addHours(12), ['user' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 8)])
            : null;
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    }

    public function scopeStaff($query)
    {
        return $query->where('role', Role::Staff->value);
    }
}
