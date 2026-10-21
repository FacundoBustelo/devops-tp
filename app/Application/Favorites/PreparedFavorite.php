<?php

namespace App\Application\Favorites;

use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Gifs\GifId;

final readonly class PreparedFavorite
{
    public function __construct(public int $userId, public GifId $gifId, public FavoriteAlias $alias)
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('Invalid user ID.');
        }
    }
}
