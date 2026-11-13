<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'activeStaff' => User::staff()->where('is_active', true)->count(),
            'lockedStaff' => User::staff()->where('is_active', false)->count(),
            'recentAudit' => AuditLog::with('user')->latest('created_at')->limit(8)->get(),
        ]);
    }
}
