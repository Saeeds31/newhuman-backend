<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::post('/upload-image', [DashboardController::class, 'uploadImage'])->name('uploadImage');
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');
});
