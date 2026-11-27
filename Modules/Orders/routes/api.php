<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\LearningProgressController;
use Modules\Orders\Http\Controllers\OrdersController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('orders', OrdersController::class)->names('orders');
    Route::post('/orders-create-by-admin', [OrdersController::class, "storeInAdmin"])->name("storeInAdmin");
    Route::post('/orders-change-status/{order}', [OrdersController::class, "changeStatus"])->name("changeStatus");
    Route::get('/orders-todays-orders', [OrdersController::class, "todaysOrders"])->name("todaysOrders");

    // گزارش پایش دانشجویان، داشبورد تحلیلی و اقدامات کوچینگ در پنل ادمین
    Route::get('/learning/analytics', [LearningProgressController::class, 'adminDashboardAnalytics']);
    Route::get('/learning/students', [LearningProgressController::class, 'adminStudentsOverview']);
    Route::get('/learning/students/{userId}', [LearningProgressController::class, 'adminStudentDetailReport']);
    Route::post('/learning/students/{userId}/coaching-action', [LearningProgressController::class, 'storeCoachingAction']);
});

Route::middleware(['auth:sanctum'])->prefix('v1/front')->group(function () {
    Route::post('/order', [OrdersController::class, "checkout"])->name("checkout");
    Route::post('/checkout-summary', [OrdersController::class, "checkoutSummary"])->name("checkoutSummary");
    Route::get('/user/orders', [OrdersController::class, 'userDashboardOrders']);
    Route::get('/user/orders/{order}', [OrdersController::class, 'userDashboardOrderDetail']);

    // ثبت و دریافت پیشرفت یادگیری و رویدادهای رفتاری دانشجو
    Route::post('/learning/progress', [LearningProgressController::class, 'recordProgress']);
    Route::post('/learning/event', [LearningProgressController::class, 'recordActivityEvent']);
    Route::get('/learning/progress/{productId}', [LearningProgressController::class, 'userCourseProgress']);
});