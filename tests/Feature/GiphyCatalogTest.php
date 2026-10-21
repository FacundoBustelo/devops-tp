<?php

namespace Tests\Feature;

use App\Domain\Gifs\CatalogTimeout;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\InvalidCatalogResponse;
use App\Domain\Gifs\SearchCriteria;
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

final class GiphyCatalogTest extends TestCase
{
    private function fake($callback = null): void
    {
        OfflineHttp::reset();
        Http::fake($callback);
    }

    public static function payload(int $offset = 0): array
    {
        return ['data' => [
            ['id' => 'AbC123', 'title' => '', 'url' => 'https://giphy.com/gifs/AbC123', 'images' => ['original' => ['url' => 'https://media.giphy.com/a.gif?x=1&y=2']]],
            ['id' => 'DEF', 'title' => '密', 'url' => 'https://giphy.com/gifs/DEF', 'images' => ['original' => ['url' => 'https://media.giphy.com/b.gif']]],
        ], 'pagination' => ['offset' => $offset, 'count' => 2]];
    }

    public function test_exact_parameters_mapping_order_optional_total_and_transport_options(): void
    {
        $payload = self::payload(7);
        $this->fake(function ($request, $options) use ($payload) {
            self::assertSame(2.0, $options['connect_timeout']);
            self::assertSame(5.0, $options['timeout']);
            self::assertFalse($options['allow_redirects']);
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            self::assertSame(['api_key' => 'external-secret', 'q' => '  密 + & %  ', 'limit' => '25', 'offset' => '7'], $query);
            self::assertSame('https://api.giphy.com/v1/gifs/search', strtok($request->url(), '?'));

            return Http::response($payload);
        });
        $result = app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('  密 + & %  ', 25, 7));
        self::assertSame(['AbC123', 'DEF'], array_column($result->gifs, 'id'));
        self::assertSame($payload['data'][0]['images']['original']['url'], $result->gifs[0]->imageUrl);
        self::assertNull($result->totalCount);
        Http::assertSentCount(1);
        $payload['pagination']['total_count'] = 200;
        $this->fake(['*' => Http::response($payload)]);
        self::assertSame(200, app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x', 25, 7))->totalCount);
        config(['giphy.connect_timeout' => 1.5, 'giphy.timeout' => 4.5]);
        $this->fake(function ($request, $options) {
            self::assertSame(1.5, $options['connect_timeout']);
            self::assertSame(4.5, $options['timeout']);

            return Http::response(['data' => [], 'pagination' => ['offset' => 0, 'count' => 0, 'total_count' => 0]]);
        });
        self::assertSame([], app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x'))->gifs);
    }

    public function test_incompatible_responses_invalidate_the_whole_collection(): void
    {
        $base = self::payload();
        $cases = ['invalid json', '{}', '{"data":{},"pagination":{"offset":0,"count":0}}'];
        foreach ([['data', 1, 'id'], ['data', 0, 'images'], ['pagination', 'offset'], ['pagination', 'count']] as $path) {
            $bad = $base;
            $ref = &$bad;
            foreach (array_slice($path, 0, -1) as $key) {
                $ref = &$ref[$key];
            }
            unset($ref[end($path)]);
            unset($ref);
            $cases[] = $bad;
        }
        foreach (['offset' => [1, -1, '0', 0.0], 'count' => [1, -1, '2', 2.0], 'total_count' => [-1, null, '2', 2.0]] as $field => $values) {
            foreach ($values as $value) {
                $bad = $base;
                $bad['pagination'][$field] = $value;
                $cases[] = $bad;
            }
        }
        foreach ([123, '', 'not-url'] as $value) {
            $bad = $base;
            $bad['data'][1]['url'] = $value;
            $cases[] = $bad;
        }
        $bad = $base;
        $bad['data'][1]['id'] = 123;
        $cases[] = $bad;
        $bad = $base;
        $bad['data'][1]['title'] = null;
        $cases[] = $bad;
        foreach ($cases as $case) {
            $this->fake(['*' => Http::response(is_array($case) ? json_encode($case, JSON_PRESERVE_ZERO_FRACTION) : $case)]);
            try {
                app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x'));
                self::fail('Malformed response accepted');
            } catch (InvalidCatalogResponse $e) {
                self::assertNull($e->getPrevious());
            }
        }
        $this->fake(['*' => Http::response($base)]);
        $this->expectException(InvalidCatalogResponse::class);
        app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x', 1));
    }

    public function test_guzzle_connection_total_and_response_timeouts_are_classified_before_http_conversion(): void
    {
        $request = new Request('GET', 'https://api.giphy.com/v1/gifs/search?api_key=external-secret');
        foreach ([
            new ConnectTimeoutException('technical secret', $request),
            new NetworkTimeoutException('technical secret', $request),
            new ResponseTimeoutException('technical secret', $request, new Response(200)),
            new ResponseTimeoutException('technical secret', $request, new Response(503)),
        ] as $error) {
            $attempts = 0;
            $this->fake(function () use ($error, &$attempts) {
                $attempts++;

                return Create::rejectionFor($error);
            });
            try {
                app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x'));
                self::fail('Timeout accepted');
            } catch (CatalogTimeout $e) {
                self::assertNull($e->getPrevious());
                self::assertSame('', $e->getMessage());
            }
            self::assertSame(1, $attempts);
        }
    }

    public function test_status_mapping_missing_key_no_retries_and_sanitized_transport_errors(): void
    {
        foreach ([301 => InvalidCatalogResponse::class, 302 => InvalidCatalogResponse::class, 404 => InvalidCatalogResponse::class, 400 => InvalidCatalogResponse::class, 204 => InvalidCatalogResponse::class, 401 => CatalogUnavailable::class, 403 => CatalogUnavailable::class, 429 => CatalogUnavailable::class, 500 => CatalogUnavailable::class, 503 => CatalogUnavailable::class] as $status => $expected) {
            $this->fake(['*' => Http::response('api_key=external-secret', $status)]);
            try {
                app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x'));
                self::fail('Error accepted');
            } catch (\RuntimeException $e) {
                self::assertSame($expected, $e::class);
                self::assertSame('', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
            Http::assertSentCount(1);
        }
        foreach ([6 => CatalogUnavailable::class, 28 => CatalogTimeout::class] as $errno => $expected) {
            $attempts = 0;
            $this->fake(function () use ($errno, &$attempts) {
                $attempts++;
                throw new ConnectionException('api_key=external-secret', 0, ($errno === 28 ? new ConnectTimeoutException('technical secret', new Request('GET', 'https://api.giphy.com')) : new ConnectException('technical secret', new Request('GET', 'https://api.giphy.com'))));
            });
            try {
                app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x'));
                self::fail('Transport error accepted');
            } catch (\RuntimeException $e) {
                self::assertSame($expected, $e::class);
                self::assertNull($e->getPrevious());
                self::assertSame('', $e->getMessage());
            }
            self::assertSame(1, $attempts);
        }
        config(['giphy.api_key' => '']);
        $this->fake();
        try {
            app(GiphyHttpGifCatalog::class)->search(new SearchCriteria('x'));
            self::fail('Missing key accepted');
        } catch (CatalogUnavailable) {
            Http::assertNothingSent();
        }
    }
}
