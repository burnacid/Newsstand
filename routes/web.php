<?php

use App\Http\Controllers\OpmlExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Signed-in visitors go straight to the reader.
Route::get('/', fn (Request $request) => $request->user() ? redirect()->route('reader') : view('welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The sidebar "Dashboards" link: your default dashboard, or the overview when none is set.
    Route::get('dashboard', function (Request $request) {
        $dashboard = $request->user()->defaultDashboard();

        return $dashboard ? redirect()->route('dashboards.show', $dashboard) : redirect()->route('dashboards.index');
    })->name('dashboard');

    Route::livewire('dashboards', 'pages::dashboards.index')->name('dashboards.index');
    Route::livewire('dashboards/{dashboard}', 'pages::dashboards.show')->name('dashboards.show');
    Route::livewire('reader', 'pages::reader.index')->name('reader');

    Route::livewire('feeds', 'pages::feeds.index')->name('feeds.index');
    Route::livewire('security', 'pages::security.index')->name('security');
    Route::livewire('security/cves/{cveId}', 'pages::security.cve')->where('cveId', '(?i)CVE-\d{4}-\d{4,}')->name('security.cve');
    Route::livewire('watchlist', 'pages::watchlist.index')->name('watchlist');
    Route::livewire('rules', 'pages::rules.index')->name('rules');
    Route::livewire('teams', 'pages::teams.index')->name('teams.index');
    Route::livewire('teams/join/{token}', 'pages::teams.join')->middleware('throttle:30,1')->name('teams.join');
    Route::livewire('teams/{team}', 'pages::teams.show')->whereNumber('team')->name('teams.show');
    Route::get('feeds/export', OpmlExportController::class)->name('feeds.export');
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/users')->name('index');
    Route::livewire('users', 'pages::admin.users')->name('users');
    Route::livewire('settings', 'pages::admin.settings')->name('settings');
    Route::livewire('health', 'pages::admin.health')->name('health');
});

// Public read-only dashboards (no login). Rate limited; tokens are 40 random characters.
Route::livewire('d/{token}', 'pages::dashboards.public')->middleware('throttle:60,1')->name('dashboards.public');

require __DIR__.'/settings.php';
