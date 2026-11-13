<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\PricingModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'pricing_model' => PricingModel::class,
            'occurrence_date' => 'date',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'price' => 'decimal:2',
            'is_detached' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** Always store UTC, whatever timezone the Carbon instance was built in. */
    protected function scheduledStart(): Attribute
    {
        return Attribute::set(fn ($v) => $v === null ? null : Carbon::parse($v)->utc()->format('Y-m-d H:i:s'));
    }

    protected function scheduledEnd(): Attribute
    {
        return Attribute::set(fn ($v) => $v === null ? null : Carbon::parse($v)->utc()->format('Y-m-d H:i:s'));
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function schedule()
    {
        return $this->belongsTo(RecurringSchedule::class, 'recurring_schedule_id');
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function staff()
    {
        return $this->belongsToMany(User::class, 'appointment_staff')->withPivot('notified_at');
    }

    public function timeEntries()
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function localStart(): Carbon
    {
        return $this->scheduled_start->copy()->tz(config('apq.display_timezone'));
    }

    public function localEnd(): Carbon
    {
        return $this->scheduled_end->copy()->tz(config('apq.display_timezone'));
    }

    public function durationMinutes(): int
    {
        return (int) $this->scheduled_start->diffInMinutes($this->scheduled_end);
    }

    public function timeRange(): string
    {
        $fmt = app()->getLocale() === 'es' ? 'H:i' : 'g:ia';

        return $this->localStart()->format($fmt).' – '.$this->localEnd()->format($fmt);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, AppointmentStatus::active()));
    }

    /** Appointments whose local (Sydney) date falls in [from, to]. */
    public function scopeBetweenLocalDates($query, Carbon $from, Carbon $to)
    {
        $tz = config('apq.display_timezone');

        return $query->where('scheduled_start', '>=', $from->copy()->tz($tz)->startOfDay()->utc())
            ->where('scheduled_start', '<=', $to->copy()->tz($tz)->endOfDay()->utc());
    }

    public function scopeForStaff($query, User $user)
    {
        return $query->whereHas('staff', fn ($q) => $q->whereKey($user->id));
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->staff()->whereKey($user->id)->exists();
    }
}
