<?php

namespace Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

final class MutateFinalResponse
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);
        $response->setData(['final' => true, 'token' => 'middleware-secret']);
        $response->setStatusCode(202);

        return $response;
    }
}
