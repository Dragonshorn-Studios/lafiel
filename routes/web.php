<?php

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::get('setup', [SetupController::class, 'show'])->name('setup');
Route::post('setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
