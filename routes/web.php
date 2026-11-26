<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/run-books-setup-9821', function () {
    Artisan::call('migrate', ['--force' => true]);
    $migrateOut = Artisan::output();

    Artisan::call('db:seed', [
        '--class' => 'Modules\\Products\\Database\\Seeders\\PrintedBooksSeeder',
        '--force' => true,
    ]);
    $seedOut = Artisan::output();

    return response()->json([
        'migrate' => $migrateOut,
        'seed' => $seedOut,
    ]);
});