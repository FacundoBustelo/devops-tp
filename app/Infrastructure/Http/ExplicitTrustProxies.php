<?php

namespace App\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;

final class ExplicitTrustProxies
{
    public function handle(Request $request, Closure $next): mixed
    {
        $proxies = array_filter(config('audit.trusted_proxies', []), function ($entry) {
            if (! is_string($entry)) {
                return false;
            }
            $parts = explode('/', $entry);
            if (! filter_var($parts[0], FILTER_VALIDATE_IP)) {
                return false;
            }

            return count($parts) === 1 || (count($parts) === 2 && ctype_digit($parts[1]) && (int) $parts[1] > 0 && (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
        });
        $request::setTrustedProxies(array_values($proxies), Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        return $next($request);
    }
}
