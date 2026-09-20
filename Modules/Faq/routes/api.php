<?php

use Illuminate\Support\Facades\Route;
use Modules\Faq\Http\Controllers\FaqController;

Route::prefix('v1/admin')->middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('/faqs', FaqController::class);
    Route::get('/faqs-all-active', [FaqController::class, 'allActive']);
    Route::get('/products/{productId}/faqs', [FaqController::class, 'productFaqs']);
    Route::post('/products/{productId}/faqs/sync', [FaqController::class, 'syncProductFaqs']);
});
