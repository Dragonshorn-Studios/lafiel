<?php

use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('setup', [SetupController::class, 'show'])->name('setup');
Route::post('setup', [SetupController::class, 'store'])->name('setup.store');

// Public alias for the old dashboard URL; the Overview behind it is not.
Route::redirect('dashboard', '/')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('/', 'pages::overview.index')->name('overview');

    Route::livewire('costs', 'pages::costs.index')->name('costs.index');
    Route::livewire('costs/history', 'pages::costs.history')->name('costs.history');
    Route::livewire('costs/renewals', 'pages::costs.renewals')->name('costs.renewals');
});

require __DIR__.'/settings.php';
