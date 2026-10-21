<?php

namespace App\Domain\Gifs;

interface GifCatalog
{
    public function findById(GifId $id): ?Gif;

    public function search(SearchCriteria $criteria): SearchResult;
}
