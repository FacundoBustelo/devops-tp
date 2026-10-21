<?php

namespace App\Application\Favorites;

use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Favorites\FavoriteGif;
use App\Domain\Favorites\FavoriteGifRepository;
use App\Domain\Favorites\IncorrectFavoriteOwner;
use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\GifNotFound;

final readonly class SaveFavoriteGif
{
    public function __construct(private GifCatalog $catalog, private FavoriteGifRepository $repository) {}

    public function prepare(int $actor, int $owner, GifId $id, FavoriteAlias $alias): PreparedFavorite
    {
        if ($actor !== $owner) {
            throw new IncorrectFavoriteOwner;
        }
        $entry = new PreparedFavorite($actor, $id, $alias);
        if ($this->catalog->findById($id) === null) {
            throw new GifNotFound;
        }

        return $entry;
    }

    public function persist(PreparedFavorite $entry): FavoriteGif
    {
        return $this->repository->insert($entry->userId, $entry->gifId, $entry->alias);
    }
}
