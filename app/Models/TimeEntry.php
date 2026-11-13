<?php

namespace App\Models;

use App\Enums\TimeEntryStatus;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

class TimeEntry extends Model
{
    use HasFactory;

    /** Written only by the timer actions and admin requests, never from raw input. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TimeEntryStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'original_started_at' => 'datetime',
            'original_ended_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'staff_adjusted' => 'boolean',
            'admin_adjusted' => 'boolean',
            'auto_stopped' => 'boolean',
            'is_legacy' => 'boolean',
            'charge_rate' => 'decimal:2',
            'pay_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Invoiced entries are frozen: corrections go through Xero (void / credit note) first.
        static::updating(function (TimeEntry $e) {
            if ($e->getOriginal('invoice_line_id') !== null && array_diff(array_keys($e->getDirty()), ['updated_at']) !== []) {
                throw new LogicException('Invoiced time entries cannot be changed.');
            }
        });
    }

    protected function startedAt(): Attribute
    {
        return Attribute::set(fn ($v) => $v === null ? null : Carbon::parse($v)->utc()->format('Y-m-d H:i:s'));
    }

    protected function endedAt(): Attribute
    {
        return Attribute::set(fn ($v) => $v === null ? null : Carbon::parse($v)->utc()->format('Y-m-d H:i:s'));
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function localStart(): Carbon
    {
        return $this->started_at->copy()->tz(config('apq.display_timezone'));
    }

    public function localEnd(): ?Carbon
    {
        return $this->ended_at?->copy()->tz(config('apq.display_timezone'));
    }

    /** Running much longer than planned (scheduled end + `timer.overrun_alert_hours`). */
    public function isOverrunning(): bool
    {
        $hours = (int) app(Settings::class)->get('timer.overrun_alert_hours', 3);

        return $this->status === TimeEntryStatus::Running
            && now()->gt($this->appointment->scheduled_end->copy()->addHours($hours));
    }

    public function scopeRunning($query)
    {
        return $query->where('status', TimeEntryStatus::Running->value);
    }

    /** Labour cost of this entry, using the snapshot rate if approved, otherwise the cleaner's current rate. */
    public function labourCost(): ?float
    {
        $rate = $this->pay_rate ?? $this->user?->payRateFor($this->localStart());

        return $rate === null || $this->duration_minutes === null ? null : round($this->duration_minutes / 60 * (float) $rate, 2);
    }

    public static function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' m';
    }
}
