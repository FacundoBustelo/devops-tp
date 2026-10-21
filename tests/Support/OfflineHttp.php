<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

final class OfflineHttp
{
    public static function configure(): void
    {
        config(['giphy.api_key' => 'external-secret', 'giphy.connect_timeout' => 2.0, 'giphy.timeout' => 5.0]);
        self::reset();
    }

    public static function reset(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
    }
}
