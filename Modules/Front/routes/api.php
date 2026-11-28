<?php

use App\Http\Controllers\Api\V1\ConsultationController;
use Illuminate\Support\Facades\Route;
use Modules\Front\Http\Controllers\FrontController;

Route::prefix('v1/front')->group(function () {
    Route::get('categories', [FrontController::class, 'getCategories'])->name('front-categories');
    Route::get('banners', [FrontController::class, 'getBanners'])->name('front-banners');
    Route::get('menus', [FrontController::class, 'getMenus'])->name('front-menus');
    Route::get('settings', [FrontController::class, 'getSettings'])->name('front-settings');
    Route::get('podcasts', [FrontController::class, 'getPodcast'])->name('front-getPodcast');

    // نیومیت: دریافت تقویم ظرفیت خالی و ثبت رزرو جلسه مشاوره
    Route::get('consultations/availability', [ConsultationController::class, 'availability'])->name('front-consultations-availability');
    Route::post('consultations/book', [ConsultationController::class, 'book'])->name('front-consultations-book');
});