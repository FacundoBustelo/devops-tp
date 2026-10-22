<?php

namespace App\Infrastructure\Observability;

use Illuminate\Http\Request;

final class HttpLabels
{
    public static function method(Request $request): string
    {
        $method = strtoupper($request->getMethod());

        return in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true) ? $method : 'OTHER';
    }

    public static function route(Request $request): string
    {
        return $request->route()?->getName() ?? 'api.unmatched';
    }
}
