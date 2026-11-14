<?php

use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\ChangelogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientImportController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\RecurringScheduleController;
use App\Http\Controllers\Admin\ScheduleBoardController;
use App\Http\Controllers\Admin\ScheduleImportController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TimesheetController;
use App\Http\Controllers\Admin\XeroController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Staff\JobController;
use App\Http\Controllers\Staff\ProfileController;
use App\Http\Controllers\Staff\TodayController;
use App\Http\Controllers\Staff\WeekController;
use App\Http\Controllers\Webhooks\XeroWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', LocaleController::class)->whereIn('locale', ['en', 'es'])->name('locale.switch');

// ---- Webhooks (no session; CSRF-exempt in bootstrap/app.php) ----
Route::post('/webhooks/xero', XeroWebhookController::class)->name('webhooks.xero');

// ---- Guests ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.store');
});

// ---- Signed-in users ----
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');
    Route::get('/avatars/{user}', [ProfileController::class, 'avatar'])->middleware('signed')->name('avatar');

    Route::middleware('force.password')->group(function () {
        Route::get('/', HomeController::class)->name('home');

        // Staff portal (admins can use it too — owner-operators clean as well).
        Route::get('/jobs/today', TodayController::class)->name('jobs.today');
        Route::get('/jobs/week/{week?}', WeekController::class)->where('week', '\d{4}-W\d{2}')->name('jobs.week');
        Route::get('/jobs/{appointment}', [JobController::class, 'show'])->whereNumber('appointment')->name('jobs.show');
        Route::post('/jobs/{appointment}/start', [JobController::class, 'start'])->name('jobs.start');
        Route::post('/jobs/{appointment}/stop', [JobController::class, 'stop'])->name('jobs.stop');
        Route::patch('/jobs/{appointment}/times', [JobController::class, 'adjust'])->name('jobs.adjust');
        Route::post('/jobs/{appointment}/submit', [JobController::class, 'submit'])->name('jobs.submit');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

        // Admin
        Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::get('/changelog', ChangelogController::class)->name('changelog');
            Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'staff']);
            Route::post('/staff/{staff}/lock', [StaffController::class, 'lock'])->name('staff.lock');
            Route::post('/staff/{staff}/unlock', [StaffController::class, 'unlock'])->name('staff.unlock');

            Route::get('/clients/import', [ClientImportController::class, 'create'])->name('clients.import');
            Route::post('/clients/import', [ClientImportController::class, 'upload'])->name('clients.import.upload');
            Route::get('/clients/import/preview', [ClientImportController::class, 'preview'])->name('clients.import.preview');
            Route::post('/clients/import/confirm', [ClientImportController::class, 'confirm'])->name('clients.import.confirm');
            Route::post('/clients/import/cancel', [ClientImportController::class, 'cancel'])->name('clients.import.cancel');
            Route::resource('clients', ClientController::class)->except('destroy');
            Route::get('/clients/{client}/schedules/create', [RecurringScheduleController::class, 'create'])->name('schedules.create');
            Route::post('/clients/{client}/schedules', [RecurringScheduleController::class, 'store'])->name('schedules.store');
            Route::get('/schedules/{schedule}/edit', [RecurringScheduleController::class, 'edit'])->name('schedules.edit');
            Route::put('/schedules/{schedule}', [RecurringScheduleController::class, 'update'])->name('schedules.update');
            Route::delete('/schedules/{schedule}', [RecurringScheduleController::class, 'destroy'])->name('schedules.destroy');

            Route::get('/schedule', ScheduleBoardController::class)->name('schedule');
            Route::get('/schedule/template', [ScheduleImportController::class, 'template'])->name('schedule.template');
            Route::get('/schedule/import', [ScheduleImportController::class, 'create'])->name('schedule.import');
            Route::post('/schedule/import', [ScheduleImportController::class, 'upload'])->name('schedule.import.upload');
            Route::get('/schedule/import/preview', [ScheduleImportController::class, 'preview'])->name('schedule.import.preview');
            Route::post('/schedule/import/confirm', [ScheduleImportController::class, 'confirm'])->name('schedule.import.confirm');
            Route::post('/schedule/import/cancel', [ScheduleImportController::class, 'cancel'])->name('schedule.import.cancel');
            Route::resource('appointments', AppointmentController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);

            Route::get('/timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
            Route::get('/timesheets/create', [TimesheetController::class, 'create'])->name('timesheets.create');
            Route::post('/timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');
            Route::post('/timesheets/approve', [TimesheetController::class, 'approve'])->name('timesheets.approve');
            Route::get('/timesheets/{entry}/edit', [TimesheetController::class, 'edit'])->name('timesheets.edit');
            Route::put('/timesheets/{entry}', [TimesheetController::class, 'update'])->name('timesheets.update');
            Route::post('/timesheets/{entry}/reject', [TimesheetController::class, 'reject'])->name('timesheets.reject');
            Route::post('/timesheets/{entry}/stop', [TimesheetController::class, 'stop'])->name('timesheets.stop');

            Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('/invoices/run', [InvoiceController::class, 'create'])->name('invoices.run');
            Route::post('/invoices/run', [InvoiceController::class, 'store'])->name('invoices.run.store');
            Route::get('/invoices/runs/{run}', [InvoiceController::class, 'run'])->name('invoices.runs.show');
            Route::post('/invoices/retry-failed', [InvoiceController::class, 'retryFailed'])->name('invoices.retry-failed');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
            Route::post('/invoices/{invoice}/retry', [InvoiceController::class, 'retry'])->name('invoices.retry');
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');

            Route::get('/xero', [XeroController::class, 'show'])->name('xero.show');
            Route::post('/xero/connect', [XeroController::class, 'connect'])->name('xero.connect');
            Route::get('/xero/callback', [XeroController::class, 'callback'])->name('xero.callback');
            Route::post('/xero/disconnect', [XeroController::class, 'disconnect'])->name('xero.disconnect');
            Route::post('/xero/test', [XeroController::class, 'test'])->name('xero.test');
            Route::put('/xero/settings', [XeroController::class, 'saveSettings'])->name('xero.settings');
        });
    });
});
