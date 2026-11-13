<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Disable a user's account and force-logout every device immediately.
 */
class LockStaff
{
    public function __invoke(User $user, User $admin, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $admin, $reason) {
            $user->forceFill([
                'is_active' => false,
                'locked_at' => now(),
                'locked_by' => $admin->id,
                'lock_reason' => $reason,
                'remember_token' => Str::random(60),   // kills "remember me" cookies
            ])->save();

            // Database session driver: deleting the rows logs the user out everywhere, instantly.
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

            // Phase 2: auto-stop any running timer here.

            Audit::log('user.locked', $user, after: ['is_active' => false], reason: $reason);
        });
    }
}
