<?php

namespace App\Domain\Favorites;

use App\Domain\Gifs\GifId;

interface FavoriteGifRepository
{
    public function insert(int $userId, GifId $gifId, FavoriteAlias $alias): FavoriteGif;
}
