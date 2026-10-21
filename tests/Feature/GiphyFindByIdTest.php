<?php

namespace Tests\Feature;

use App\Domain\Gifs\CatalogTimeout;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\InvalidCatalogResponse;
use App\Infrastructure\Giphy\GiphyHttpGifCatalog;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\OfflineHttp;
use Tests\TestCase;

final class GiphyFindByIdTest extends TestCase
{
    private function fake($callback = null): void
    {
        OfflineHttp::reset();
        Http::fake($callback);
    }

    public static function payload(string $id = 'AbC123'): array
    {
        $gif = GiphyCatalogTest::payload()['data'][0];
        $gif['id'] = $id;

        return ['data' => $gif];
    }

    public function test_exact_encoded_segment_contract_and_configurable_transport(): void
    {
        config(['giphy.connect_timeout' => 1.5, 'giphy.timeout' => 4.5]);
        foreach (['000AbC', ' 密 + & % ? # / ', '%2F', '.', '..'] as $id) {
            $this->fake(function ($request, $options) use ($id) {
                $segment = in_array($id, ['.', '..'], true) ? str_repeat('%2E', strlen($id)) : rawurlencode($id);
                self::assertSame('/v1/gifs/'.$segment, parse_url($request->url(), PHP_URL_PATH));
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                self::assertSame(['api_key' => 'external-secret'], $query);
                self::assertSame('GET', $request->method());
                self::assertSame(1.5, $options['connect_timeout']);
                self::assertSame(4.5, $options['timeout']);
                self::assertFalse($options['allow_redirects']);

                return Http::response(self::payload($id));
            });
            $gif = app(GiphyHttpGifCatalog::class)->findById(new GifId($id));
            self::assertSame($id, $gif->id);
            self::assertSame('', $gif->title);
            self::assertSame(self::payload()['data']['images']['original']['url'], $gif->imageUrl);
            Http::assertSentCount(1);
        }
    }

    public function test_only_coherent_404_confirms_absence_and_bad_contracts_are_sanitized(): void
    {
        $this->fake(['*' => Http::response(['data' => [], 'meta' => ['status' => 404]], 404)]);
        self::assertNull(app(GiphyHttpGifCatalog::class)->findById(new GifId('AbC123')));
        $cases = [];
        foreach (['invalid', '{}', '[]', '{"data":null}', '{"data":[]}', '{"data":{}}', json_encode(self::payload('abc123'))] as $body) {
            $cases[] = [200, $body];
        }
        foreach (['invalid', '{}', '{"data":null,"meta":{"status":404}}', '{"data":{},"meta":{"status":404}}', '{"data":[]}', '{"data":[],"meta":{"status":"404"}}', '{"data":[],"meta":{"status":404.0}}', '{"data":[],"meta":{"status":200}}', json_encode(self::payload())] as $body) {
            $cases[] = [404, $body];
        }
        foreach (['id', 'title', 'url', 'images'] as $field) {
            $bad = self::payload();
            unset($bad['data'][$field]);
            $cases[] = [200, json_encode($bad)];
        }
        foreach (['id' => 123, 'title' => null, 'url' => 'file:///secret', 'images' => ['original' => ['url' => 'bad']]] as $field => $value) {
            $bad = self::payload();
            $bad['data'][$field] = $value;
            $cases[] = [200, json_encode($bad)];
        }
        foreach ($cases as [$status, $body]) {
            $this->fake(['*' => Http::response($body, $status)]);
            $this->assertFailure(InvalidCatalogResponse::class);
            Http::assertSentCount(1);
        }
    }

    public function test_status_missing_key_connection_and_all_timeout_phases(): void
    {
        foreach ([204 => InvalidCatalogResponse::class, 302 => InvalidCatalogResponse::class, 400 => InvalidCatalogResponse::class, 401 => CatalogUnavailable::class, 403 => CatalogUnavailable::class, 429 => CatalogUnavailable::class, 500 => CatalogUnavailable::class, 503 => CatalogUnavailable::class] as $status => $expected) {
            $this->fake(['*' => Http::response('api_key=external-secret', $status)]);
            $this->assertFailure($expected);
            Http::assertSentCount(1);
        }
        $request = new Request('GET', 'https://api.giphy.com?api_key=external-secret');
        foreach ([new ConnectTimeoutException('secret', $request), new NetworkTimeoutException('secret', $request), new ResponseTimeoutException('secret', $request, new Response(200)), new ResponseTimeoutException('secret', $request, new Response(503))] as $error) {
            $attempts = 0;
            $this->fake(function () use ($error, &$attempts) {
                $attempts++;

                return Create::rejectionFor($error);
            });
            $this->assertFailure(CatalogTimeout::class);
            self::assertSame(1, $attempts);
        }
        $attempts = 0;
        $this->fake(function () use ($request, &$attempts) {
            $attempts++;
            throw new ConnectionException('external-secret', 0, new ConnectException('external-secret', $request));
        });
        $this->assertFailure(CatalogUnavailable::class);
        self::assertSame(1, $attempts);
        $this->fake();
        config(['giphy.api_key' => '']);
        $this->assertFailure(CatalogUnavailable::class);
        Http::assertNothingSent();
    }

    private function assertFailure(string $expected): void
    {
        try {
            app(GiphyHttpGifCatalog::class)->findById(new GifId('AbC123'));
            self::fail('External failure accepted');
        } catch (\RuntimeException $e) {
            self::assertSame($expected, $e::class);
            self::assertSame('', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
