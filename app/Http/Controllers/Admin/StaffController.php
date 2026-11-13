<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Staff\LockStaff;
use App\Actions\Staff\UnlockStaff;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $status = $request->query('status', 'all');

        $staff = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'locked', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_active')->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('admin.staff.index', compact('staff', 'q', 'status'));
    }

    public function create(): View
    {
        return view('admin.staff.form', ['staff' => new User(['locale' => 'en'])]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = new User;
        $user->forceFill([
            ...collect($data)->except('password')->all(),
            'password' => $data['password'],
            'must_change_password' => true,   // temporary password: user sets their own on first login
            'is_active' => true,
        ])->save();

        Audit::log('user.created', $user, after: collect($data)->except('password')->all());

        return redirect()->route('admin.staff.edit', $user)->with('status', __('admin.staff.created'));
    }

    public function edit(User $staff): View
    {
        return view('admin.staff.form', compact('staff'));
    }

    public function update(StaffRequest $request, User $staff): RedirectResponse
    {
        $data = $request->validated();

        // An admin cannot demote themselves (prevents locking everyone out of admin).
        if ($staff->is($request->user()) && $data['role'] !== Role::Admin->value) {
            return back()->withErrors(['role' => __('admin.staff.cannot_demote_self')])->withInput();
        }

        $tracked = ['name', 'email', 'mobile', 'role', 'employment_type', 'hourly_rate', 'saturday_rate', 'sunday_rate', 'abn', 'locale', 'notes'];
        $before = collect($staff->only($tracked))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all();

        $staff->forceFill(collect($data)->except('password')->all());
        if (! empty($data['password'])) {
            $staff->forceFill(['password' => $data['password'], 'must_change_password' => true]);
        }
        $staff->save();

        $after = collect($staff->only($tracked))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all();
        Audit::log('user.updated', $staff, before: array_diff_assoc($before, $after), after: array_diff_assoc($after, $before));

        return redirect()->route('admin.staff.edit', $staff)->with('status', __('admin.staff.saved'));
    }

    public function lock(Request $request, User $staff, LockStaff $lock): RedirectResponse
    {
        abort_if($staff->is($request->user()), 422, __('admin.staff.cannot_lock_self'));
        $reason = $request->validate(['lock_reason' => ['nullable', 'string', 'max:255']])['lock_reason'] ?? null;

        $lock($staff, $request->user(), $reason);

        return back()->with('status', __('admin.staff.locked', ['name' => $staff->name]));
    }

    public function unlock(User $staff, UnlockStaff $unlock): RedirectResponse
    {
        $unlock($staff);

        return back()->with('status', __('admin.staff.unlocked', ['name' => $staff->name]));
    }
}
