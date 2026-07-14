<?php

use Illuminate\Support\Facades\Route;
use Modules\File\Http\Controllers\FileController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('files', FileController::class)->names('file');
});
