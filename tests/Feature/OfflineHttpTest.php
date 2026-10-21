<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\OfflineHttp;
use Tests\TestCase;

final class OfflineHttpTest extends TestCase
{
    public function test_external_requests_are_blocked_initially_and_after_factory_reset(): void
    {
        foreach ([false, true] as $reset) {
            if ($reset) {
                OfflineHttp::reset();
            }
            try {
                Http::get('https://api.giphy.com/v1/gifs/search');
                self::fail('Unsimulated external request allowed.');
            } catch (StrayRequestException $e) {
                self::assertStringContainsString('without a matching fake', $e->getMessage());
            }
        }
    }
}
