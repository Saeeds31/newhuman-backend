<?php

use Illuminate\Support\Facades\Route;
use Modules\Discourse\Http\Controllers\DiscourseCategoryController;
use Modules\Discourse\Http\Controllers\DiscourseController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('discourse-categories', DiscourseCategoryController::class)->names('discourse');
    Route::apiResource('discourse', DiscourseController::class)->names('discourse');
});
Route::prefix('v1/front')->group(function () {
    Route::get('discourse-categories', [DiscourseCategoryController::class, 'getFrontDiscourseCategory'])->name('getFrontDiscourseCategory');
    Route::get('discourses', [DiscourseController::class, 'getFrontDiscourses'])->name('getFrontDiscourses');
});
