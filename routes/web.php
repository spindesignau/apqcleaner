<?php

use App\Http\Controllers\Admin\AppointmentController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RecurringScheduleController;
use App\Http\Controllers\Admin\ScheduleBoardController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Staff\JobController;
use App\Http\Controllers\Staff\ProfileController;
use App\Http\Controllers\Staff\TodayController;
use App\Http\Controllers\Staff\WeekController;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', LocaleController::class)->whereIn('locale', ['en', 'es'])->name('locale.switch');

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
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

        // Admin
        Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
            Route::get('/', DashboardController::class)->name('dashboard');
            Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'staff']);
            Route::post('/staff/{staff}/lock', [StaffController::class, 'lock'])->name('staff.lock');
            Route::post('/staff/{staff}/unlock', [StaffController::class, 'unlock'])->name('staff.unlock');

            Route::resource('clients', ClientController::class)->except('destroy');
            Route::get('/clients/{client}/schedules/create', [RecurringScheduleController::class, 'create'])->name('schedules.create');
            Route::post('/clients/{client}/schedules', [RecurringScheduleController::class, 'store'])->name('schedules.store');
            Route::get('/schedules/{schedule}/edit', [RecurringScheduleController::class, 'edit'])->name('schedules.edit');
            Route::put('/schedules/{schedule}', [RecurringScheduleController::class, 'update'])->name('schedules.update');
            Route::delete('/schedules/{schedule}', [RecurringScheduleController::class, 'destroy'])->name('schedules.destroy');

            Route::get('/schedule', ScheduleBoardController::class)->name('schedule');
            Route::resource('appointments', AppointmentController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
        });
    });
});
