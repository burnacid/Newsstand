<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the service worker with the release version filled in, so every release replaces the old caches.
 */
class PwaController extends Controller
{
    public function serviceWorker(): Response
    {
        $script = str_replace(
            '__NEWSSTAND_VERSION__',
            (string) config('newsstand.version'),
            (string) file_get_contents(resource_path('pwa/sw.js')),
        );

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Browsers check for a new worker on every visit; never let a proxy hold on to an old one.
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
