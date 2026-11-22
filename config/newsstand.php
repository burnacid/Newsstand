<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | "open": anyone can create an account. "closed": only the first account
    | (your admin); add others with `php artisan newsstand:user`.
    |
    */

    'registration' => env('NEWSSTAND_REGISTRATION', 'open'),

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

    /*
    |--------------------------------------------------------------------------
    | Reader
    |--------------------------------------------------------------------------
    |
    | Entries older than this many days never count as unread, so subscribing
    | to a feed with a long history doesn't bury you in a backlog.
    |
    */

    'unread_max_age_days' => (int) env('NEWSSTAND_UNREAD_MAX_AGE_DAYS', 30),

    'reader_page_size' => 40,

    /*
    |--------------------------------------------------------------------------
    | Security signals
    |--------------------------------------------------------------------------
    |
    | KEV: CISA's Known Exploited Vulnerabilities catalog. The official copy
    | on GitHub is used because cisa.gov refuses automated PHP clients.
    | NVD: CVSS scores. Without an API key NVD allows ~5 requests per 30s;
    | with a free key (https://nvd.nist.gov/developers/request-an-api-key) ~50.
    |
    */

    'security' => [
        'kev_url' => env('NEWSSTAND_KEV_URL', 'https://raw.githubusercontent.com/cisagov/kev-data/main/known_exploited_vulnerabilities.json'),
        'nvd_url' => env('NEWSSTAND_NVD_URL', 'https://services.nvd.nist.gov/rest/json/cves/2.0'),
        'nvd_api_key' => env('NVD_API_KEY'),

        // Fetch the article of teaser-only stories once to find the CVEs it mentions.
        'scan_articles' => (bool) env('NEWSSTAND_SCAN_ARTICLES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story clustering
    |--------------------------------------------------------------------------
    */

    'clustering' => [
        'window_hours' => 48,
        'min_similarity' => 0.5,
        'min_shared_words' => 3,
    ],

];
