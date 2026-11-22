<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feed fetching
    |--------------------------------------------------------------------------
    */

    'fetch' => [
        'user_agent' => env('NEWSSTAND_USER_AGENT', 'Newsstand/1.0 (+self-hosted RSS reader)'),
        'timeout' => (int) env('NEWSSTAND_FETCH_TIMEOUT', 20),
        'max_bytes' => (int) env('NEWSSTAND_FETCH_MAX_BYTES', 10 * 1024 * 1024),
        'max_redirects' => 5,

        // Refuse to fetch URLs that resolve to private / reserved IP ranges (SSRF protection).
        // Set to false on a homelab if you deliberately subscribe to feeds on your LAN.
        'block_private_hosts' => (bool) env('NEWSSTAND_BLOCK_PRIVATE_HOSTS', true),

        // Adaptive polling interval bounds, in minutes.
        'min_interval' => (int) env('NEWSSTAND_MIN_INTERVAL', 15),
        'max_interval' => (int) env('NEWSSTAND_MAX_INTERVAL', 360),

        // Upper bound for error backoff, in minutes.
        'max_backoff' => 24 * 60,

        // How many due feeds are dispatched per scheduler tick.
        'batch_size' => (int) env('NEWSSTAND_FETCH_BATCH', 50),

        // Only store this many items per fetch (newest first).
        'max_items_per_fetch' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Entries older than this many days are pruned nightly, unless someone
    | starred them or saved them for later.
    |
    */

    'retention_days' => (int) env('NEWSSTAND_RETENTION_DAYS', 90),

];
