<?php

namespace App\Domain\Gifs;

final readonly class SearchResult
{
    /** @param list<Gif> $gifs */
    public function __construct(public array $gifs, public int $limit, public int $offset, public ?int $totalCount = null)
    {
        if (! array_is_list($gifs) || count($gifs) > $limit || $limit < 1 || $limit > 50 || $offset < 0 || ($totalCount !== null && $totalCount < 0)) {
            throw new \InvalidArgumentException('Invalid search result.');
        }
        foreach ($gifs as $gif) {
            if (! $gif instanceof Gif) {
                throw new \InvalidArgumentException('Invalid GIF.');
            }
        }
    }
}
