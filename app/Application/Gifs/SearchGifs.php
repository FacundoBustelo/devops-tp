<?php

namespace App\Application\Gifs;

use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\SearchCriteria;
use App\Domain\Gifs\SearchResult;

final readonly class SearchGifs
{
    public function __construct(private GifCatalog $catalog) {}

    public function execute(SearchCriteria $criteria): SearchResult
    {
        return $this->catalog->search($criteria);
    }
}
