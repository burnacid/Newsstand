<?php

use App\Http\Controllers\OpmlExportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('feeds', 'pages::feeds.index')->name('feeds.index');
    Route::get('feeds/export', OpmlExportController::class)->name('feeds.export');
});

require __DIR__.'/settings.php';
