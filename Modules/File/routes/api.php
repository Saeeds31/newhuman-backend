<?php

use Illuminate\Support\Facades\Route;
use Modules\File\Http\Controllers\FileCategoryController;
use Modules\File\Http\Controllers\FileController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('files', FileController::class)->names('file');
    Route::apiResource('file-categories', FileCategoryController::class)->names('file-categories');
});
Route::prefix('v1/front')->group(function () {
    Route::get('files', [FileController::class, 'frontIndex'])->name('file-frontIndex');
    Route::get('files/{slug}', [FileController::class, 'frontDetail'])->name('file-frontDetail');
    Route::get('files/{slug}/comment', [FileController::class, 'storeComment'])->name('file-storeComment');
    Route::get('file_types', [FileController::class, 'fileTypes'])->name('file-fileTypes');
    Route::get('file-categories', [FileCategoryController::class,'frontIndex'])->name('file-categories-frontIndex');
});
