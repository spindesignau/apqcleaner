<?php

namespace App\Enums;

enum TimeEntryStatus: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Invoiced = 'invoiced';

    public function label(): string
    {
        return __('enums.time_entry_status.'.$this->value);
    }

    /** States in which the cleaner may still change the entry. */
    public function editableByStaff(): bool
    {
        return in_array($this, [self::Running, self::Stopped, self::Rejected], true);
    }

    /** Submitted or later: the cleaner has finished with it. */
    public function isSubmitted(): bool
    {
        return in_array($this, [self::Submitted, self::Approved, self::Invoiced], true);
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Running => 'badge-sage',
            self::Stopped => 'badge-amber',
            self::Submitted => 'badge-sand',
            self::Approved, self::Invoiced => 'badge-navy',
            self::Rejected => 'badge-alert',
        };
    }
}
