<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SESSION_SECURE_COOKIE=auto (or unset): mark the session cookie "secure" only
 * when this request really arrived over HTTPS (directly or via a trusted proxy).
 *
 * A secure cookie on a plain-HTTP site is never sent back by the browser, so every
 * form post fails with "419 Page Expired". Runs before the session starts.
 */
class ResolveSecureSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $setting = config('session.secure');

        if ($setting === null || $setting === 'auto') {
            config(['session.secure' => $request->isSecure()]);
        }

        return $next($request);
    }

    /** Secure cookies are forced on but this page came over HTTP: sign-in can't work. */
    public static function misconfigured(Request $request): bool
    {
        return config('session.secure') === true && ! $request->isSecure();
    }
}
