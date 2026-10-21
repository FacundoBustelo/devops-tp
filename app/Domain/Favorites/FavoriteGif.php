<?php

namespace App\Domain\Favorites;

use App\Domain\Gifs\GifId;

final readonly class FavoriteGif
{
    public function __construct(public int $id, public int $userId, public GifId $gifId, public FavoriteAlias $alias, public \DateTimeImmutable $createdAt, public \DateTimeImmutable $updatedAt) {}
}
