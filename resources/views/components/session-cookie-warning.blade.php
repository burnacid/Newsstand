{{-- Shown when secure cookies are forced on but the page came over plain HTTP: every form would fail with 419. --}}
@if (\App\Http\Middleware\ResolveSecureSessionCookie::misconfigured(request()))
    <flux:callout variant="danger" icon="exclamation-triangle" data-test="secure-cookie-warning"
        :heading="__('Signing in won’t work over plain HTTP')">
        <flux:callout.text>
            {{ __('This server only sends its session cookie over HTTPS (SESSION_SECURE_COOKIE=true), but this page was loaded over HTTP, so forms fail with “419 Page Expired”.') }}
            {{ __('Open it via HTTPS, or set SESSION_SECURE_COOKIE=auto and restart.') }}
        </flux:callout.text>
    </flux:callout>
@endif
