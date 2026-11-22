<?php

use App\Http\Controllers\OpmlExportController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The reader is home for now; shared dashboards arrive in a later phase.
    Route::redirect('dashboard', 'reader')->name('dashboard');
    Route::livewire('reader', 'pages::reader.index')->name('reader');

    Route::livewire('feeds', 'pages::feeds.index')->name('feeds.index');
    Route::get('feeds/export', OpmlExportController::class)->name('feeds.export');
});

require __DIR__.'/settings.php';
