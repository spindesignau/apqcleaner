<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\XeroConnection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'activeStaff' => User::staff()->where('is_active', true)->count(),
            'lockedStaff' => User::staff()->where('is_active', false)->count(),
            'running' => TimeEntry::running()->with(['user', 'appointment.client'])->orderBy('started_at')->get(),
            'awaitingApproval' => TimeEntry::where('status', TimeEntryStatus::Submitted->value)->count(),
            'todayVisits' => Appointment::query()->active()->betweenLocalDates(now(config('apq.display_timezone')), now(config('apq.display_timezone')))->count(),
            'uninvoiced' => TimeEntry::where('status', TimeEntryStatus::Approved->value)->whereNull('invoice_line_id')->where('is_legacy', false)->count(),
            'failedInvoices' => Invoice::where('sync_status', Invoice::SYNC_FAILED)->count(),
            'xeroConnected' => (bool) XeroConnection::current(),
            'recentAudit' => AuditLog::with('user')->latest('created_at')->limit(8)->get(),
        ]);
    }
}
