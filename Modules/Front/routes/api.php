<?php

use Illuminate\Support\Facades\Route;
use Modules\Front\Http\Controllers\FrontController;

Route::prefix('v1/front')->group(function () {
    Route::get('categories', [FrontController::class, 'getCategories'])->name('front-categories');
    Route::get('banners', [FrontController::class, 'getBanners'])->name('front-banners');
    Route::get('menus', [FrontController::class, 'getMenus'])->name('front-menus');
    Route::get('settings', [FrontController::class, 'getSettings'])->name('front-settings');
});
