<?php

namespace App\Models;

use App\Enums\Frequency;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecurringSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'frequency' => Frequency::class,
            'anchor_date' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function staff()
    {
        return $this->belongsToMany(User::class, 'recurring_schedule_staff');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function startTimeLabel(): string
    {
        return Carbon::createFromFormat('H:i:s', strlen($this->start_time) === 5 ? $this->start_time.':00' : $this->start_time)
            ->format(app()->getLocale() === 'es' ? 'H:i' : 'g:ia');
    }

    public function summary(): string
    {
        $day = Carbon::now()->startOfWeek()->addDays($this->weekday - 1)->translatedFormat('l');
        $when = $this->frequency === Frequency::MonthlyNthWeekday
            ? __('schedule.nth.'.$this->nth_week, ['day' => $day])
            : __('schedule.every_day', ['day' => $day]);

        return $this->frequency->label().' · '.$when.' · '.$this->startTimeLabel();
    }
}
