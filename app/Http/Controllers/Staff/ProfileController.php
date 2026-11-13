<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateOwnPasswordRequest;
use App\Http\Requests\Staff\UpdateOwnProfileRequest;
use App\Models\User;
use App\Services\AvatarStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('staff.profile', ['user' => $request->user()]);
    }

    public function update(UpdateOwnProfileRequest $request, AvatarStore $avatars): RedirectResponse
    {
        $user = $request->user();

        // Explicit whitelist — never $request->all().
        $data = $request->safe()->only(['name', 'email', 'locale']);

        if ($request->hasFile('avatar')) {
            $data['avatar_path'] = $avatars->store($user, $request->file('avatar'));
        } elseif ($request->boolean('remove_avatar') && $user->avatar_path) {
            Storage::disk(config('apq.avatars.disk'))->delete($user->avatar_path);
            $data['avatar_path'] = null;
        }

        if ($data['email'] !== $user->email) {
            $data['email_verified_at'] = null;
        }

        $user->forceFill($data)->save();
        $request->session()->put('locale', $user->locale);

        return redirect()->route('profile.edit')->with('status', __('profile.saved'));
    }

    public function password(UpdateOwnPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),   // invalidate "remember me" on other devices
        ])->save();

        // Sign out every other device that was logged in with the old password.
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return redirect()->route('profile.edit')->with('status', __('profile.password_updated'));
    }

    /** Served through a signed URL so avatars never sit on a public disk. */
    public function avatar(Request $request, User $user): StreamedResponse
    {
        abort_unless($user->avatar_path, 404);

        return Storage::disk(config('apq.avatars.disk'))->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=43200',
        ]);
    }
}
