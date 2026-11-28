<?php

use App\Http\Controllers\Api\V1\ConsultationController;
use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::post('/upload-image', [DashboardController::class, 'uploadImage'])->name('uploadImage');
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');

    // مدیریت نیومیت: تنظیم مبلغ بیعانه، برنامه هفتگی، مسدودسازی اسلات و لیست رزروها
    Route::prefix('consultations')->group(function () {
        Route::get('/settings', [ConsultationController::class, 'adminSettings']);
        Route::put('/settings', [ConsultationController::class, 'adminUpdateSettings']);
        Route::get('/bookings', [ConsultationController::class, 'adminBookings']);
        Route::post('/manual-slot', [ConsultationController::class, 'adminManualSlot']);
        Route::patch('/bookings/{id}/status', [ConsultationController::class, 'adminUpdateBookingStatus']);
    });
});