<?php

namespace App\Infrastructure\Giphy;

use App\Domain\Gifs\CatalogTimeout;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\Gif;
use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\InvalidCatalogResponse;
use App\Domain\Gifs\SearchCriteria;
use App\Domain\Gifs\SearchResult;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class GiphyHttpGifCatalog implements GifCatalog
{
    public function search(SearchCriteria $criteria): SearchResult
    {
        $body = $this->decode($this->request('search', [
            'q' => $criteria->query, 'limit' => $criteria->limit, 'offset' => $criteria->offset,
        ]));
        if (! $body instanceof \stdClass || ! isset($body->data, $body->pagination) || ! is_array($body->data) || ! $body->pagination instanceof \stdClass) {
            throw new InvalidCatalogResponse;
        }
        $page = $body->pagination;
        if (! isset($page->offset, $page->count) || ! is_int($page->offset) || ! is_int($page->count) || $page->offset !== $criteria->offset || $page->count !== count($body->data) || $page->count > $criteria->limit) {
            throw new InvalidCatalogResponse;
        }
        $total = null;
        if (property_exists($page, 'total_count')) {
            if (! is_int($page->total_count) || $page->total_count < 0) {
                throw new InvalidCatalogResponse;
            }
            $total = $page->total_count;
        }
        $gifs = [];
        foreach ($body->data as $item) {
            $gifs[] = $this->gif($item);
        }

        return new SearchResult($gifs, $criteria->limit, $page->offset, $total);
    }

    public function findById(GifId $id): ?Gif
    {
        // rawurlencode leaves dot segments unchanged; encode them to prevent path normalization.
        $segment = in_array($id->value, ['.', '..'], true) ? str_repeat('%2E', strlen($id->value)) : rawurlencode($id->value);
        $response = $this->request($segment, allowNotFound: true);
        $body = $this->decode($response);
        if ($response->status() === 404) {
            if ($body instanceof \stdClass && isset($body->data, $body->meta)
                && $body->data === [] && $body->meta instanceof \stdClass
                && isset($body->meta->status) && $body->meta->status === 404) {
                return null;
            }

            throw new InvalidCatalogResponse;
        }
        if (! $body instanceof \stdClass || ! isset($body->data)) {
            throw new InvalidCatalogResponse;
        }
        $gif = $this->gif($body->data);
        if ($gif->id !== $id->value) {
            throw new InvalidCatalogResponse;
        }

        return $gif;
    }

    private function request(string $path, array $query = [], bool $allowNotFound = false): Response
    {
        $key = config('giphy.api_key');
        if (! is_string($key) || trim($key) === '') {
            throw new CatalogUnavailable;
        }
        try {
            $response = Http::acceptJson()->connectTimeout(config('giphy.connect_timeout'))
                ->timeout(config('giphy.timeout'))->withoutRedirecting()
                ->withMiddleware(function (callable $handler) {
                    return function ($request, $options) use ($handler) {
                        try {
                            $promise = $handler($request, $options);
                        } catch (TransferException $e) {
                            throw $this->transportFailure($e);
                        }

                        // Classify timeouts after headers before Laravel converts them into HTTP errors.
                        return $promise->otherwise(fn ($e) => throw $this->transportFailure($e));
                    };
                })
                ->get('https://api.giphy.com/v1/gifs/'.$path, ['api_key' => $key] + $query);
        } catch (ConnectionException $exception) {
            throw $this->transportFailure($exception);
        }
        if (in_array($response->status(), [401, 403, 429], true) || $response->serverError()) {
            throw new CatalogUnavailable;
        }
        if ($response->status() !== 200 && ! ($allowNotFound && $response->status() === 404)) {
            throw new InvalidCatalogResponse;
        }

        return $response;
    }

    private function decode(Response $response): mixed
    {
        try {
            return json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidCatalogResponse;
        }
    }

    private function gif(mixed $item): Gif
    {
        if (! $item instanceof \stdClass || ! isset($item->id, $item->title, $item->url, $item->images->original->url)
            || ! is_string($item->id) || $item->id === '' || ! is_string($item->title)
            || ! $this->isUrl($item->url) || ! $this->isUrl($item->images->original->url)) {
            throw new InvalidCatalogResponse;
        }

        return new Gif($item->id, $item->title, $item->url, $item->images->original->url);
    }

    private function transportFailure(\Throwable $exception): \Throwable
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectTimeoutException
                || $cause instanceof NetworkTimeoutException
                || $cause instanceof ResponseTimeoutException
                || (method_exists($cause, 'getHandlerContext') && ($cause->getHandlerContext()['errno'] ?? null) === 28)) {
                return new CatalogTimeout;
            }
        }
        if ($exception instanceof ConnectionException || $exception instanceof TransferException) {
            return new CatalogUnavailable;
        }

        return $exception;
    }

    private function isUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
