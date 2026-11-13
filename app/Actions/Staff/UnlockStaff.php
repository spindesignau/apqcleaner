<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Services\Audit;

class UnlockStaff
{
    public function __invoke(User $user): void
    {
        $user->forceFill([
            'is_active' => true,
            'locked_at' => null,
            'locked_by' => null,
            'lock_reason' => null,
        ])->save();

        Audit::log('user.unlocked', $user, after: ['is_active' => true]);
    }
}
