<?php

namespace App\Infrastructure\Favorites;

use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Favorites\FavoriteAlreadyExists;
use App\Domain\Favorites\FavoriteGif;
use App\Domain\Favorites\FavoriteGifRepository;
use App\Domain\Gifs\GifId;
use Illuminate\Database\QueryException;

final class EloquentFavoriteGifRepository implements FavoriteGifRepository
{
    public function insert(int $userId, GifId $gifId, FavoriteAlias $alias): FavoriteGif
    {
        try {
            $row = FavoriteGifModel::create(['user_id' => $userId, 'gif_id' => $gifId->value, 'alias' => $alias->value]);
        } catch (QueryException $e) {
            $info = $e->errorInfo;
            $duplicate = (($info[1] ?? null) === 1062 && preg_match("/for key ['`](?:favorite_gifs\.)?favorite_gifs_user_gif_unique['`]/", $info[2] ?? ''))
                || (($info[1] ?? null) === 19 && ($info[2] ?? '') === 'UNIQUE constraint failed: favorite_gifs.user_id, favorite_gifs.gif_id');
            if ($duplicate) {
                throw new FavoriteAlreadyExists;
            }
            throw $e;
        }

        return new FavoriteGif($row->id, $userId, $gifId, $alias, \DateTimeImmutable::createFromInterface($row->created_at), \DateTimeImmutable::createFromInterface($row->updated_at));
    }
}
