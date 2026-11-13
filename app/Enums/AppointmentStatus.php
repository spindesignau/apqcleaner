<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case CancelledLate = 'cancelled_late';
    case NoAccess = 'no_access';
    case Invoiced = 'invoiced';

    public function label(): string
    {
        return __('enums.appointment_status.'.$this->value);
    }

    /** Visits that still occupy the cleaner's time. */
    public static function active(): array
    {
        return [self::Scheduled, self::InProgress, self::Completed, self::Invoiced];
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::Cancelled, self::CancelledLate, self::NoAccess], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Scheduled => 'badge-sand',
            self::InProgress => 'badge-sage',
            self::Completed, self::Invoiced => 'badge-navy',
            self::Cancelled => 'badge-sand line-through',
            self::CancelledLate, self::NoAccess => 'badge-amber',
        };
    }
}
