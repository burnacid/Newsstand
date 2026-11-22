<?php

namespace App\Support;

class FeedTitle
{
    /**
     * Strip boilerplate that CMSes append to feed titles,
     * e.g. "Krebs on Security » Feed" or "Example Blog - RSS".
     */
    public static function clean(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = trim(preg_replace('/\s+/u', ' ', $title));
        $title = preg_replace('/\s*[»|:\-–—]\s*(rss|atom|feed|rss feed|atom feed|posts|all posts|blog feed)$/iu', '', $title);
        $title = preg_replace('/^(rss|atom)\s*[:\-–—]\s*/iu', '', $title);
        $title = trim($title);

        return $title === '' ? null : mb_substr($title, 0, 250);
    }
}
