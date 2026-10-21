<?php

namespace App\Application\Gifs;

use App\Domain\Gifs\Gif;
use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\GifNotFound;

final readonly class GetGifById
{
    public function __construct(private GifCatalog $catalog) {}

    public function execute(GifId $id): Gif
    {
        return $this->catalog->findById($id) ?? throw new GifNotFound;
    }
}
