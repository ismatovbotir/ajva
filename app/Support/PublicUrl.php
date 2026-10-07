<?php

namespace App\Support;

/**
 * Absolute links shown in the admin panel (MCP endpoint, public monitor link).
 * They are built from APP_URL, never from the host of the current request, so
 * they always carry the server's public address and port (for example
 * http://213.230.108.106:8000) even when an admin browses via another name.
 */
class PublicUrl
{
    public static function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function to(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    /** True while APP_URL still points at this machine, so the link won't work from elsewhere. */
    public static function looksLocal(): bool
    {
        return (bool) preg_match('~^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)~i', self::base());
    }
}
