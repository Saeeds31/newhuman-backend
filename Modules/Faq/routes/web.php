<?php

use Illuminate\Support\Facades\Route;
use Modules\Faq\Http\Controllers\FaqController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('faqs', FaqController::class)->names('faq');
});
