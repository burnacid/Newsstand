<?php

use App\Http\Controllers\OpmlExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Home after login: your most recently updated dashboard, or the reader.
    Route::get('dashboard', function (Request $request) {
        $dashboard = $request->user()->dashboards()->reorder()->latest('updated_at')->first()
            ?? $request->user()->sharedDashboards()->reorder()->latest('dashboard_user.updated_at')->first();

        return $dashboard ? redirect()->route('dashboards.show', $dashboard) : redirect()->route('reader');
    })->name('dashboard');

    Route::livewire('dashboards', 'pages::dashboards.index')->name('dashboards.index');
    Route::livewire('dashboards/{dashboard}', 'pages::dashboards.show')->name('dashboards.show');
    Route::livewire('reader', 'pages::reader.index')->name('reader');

    Route::livewire('feeds', 'pages::feeds.index')->name('feeds.index');
    Route::livewire('security', 'pages::security.index')->name('security');
    Route::livewire('rules', 'pages::rules.index')->name('rules');
    Route::get('feeds/export', OpmlExportController::class)->name('feeds.export');
});

// Public read-only dashboards (no login). Rate limited; tokens are 40 random characters.
Route::livewire('d/{token}', 'pages::dashboards.public')->middleware('throttle:60,1')->name('dashboards.public');

require __DIR__.'/settings.php';
