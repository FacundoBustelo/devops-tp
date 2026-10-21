<?php

namespace App\Infrastructure\Http;

use App\Application\Favorites\SaveFavoriteGif;
use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Gifs\GifId;
use App\Infrastructure\Favorites\SaveFavoriteTransaction;
use Illuminate\Http\JsonResponse;

final class SaveFavoriteGifController
{
    public function __invoke(SaveFavoriteGifRequest $request, SaveFavoriteGif $save, SaveFavoriteTransaction $transaction): JsonResponse
    {
        $entry = $save->prepare((int) $request->user()->getAuthIdentifier(), (int) $request->validated('USER_ID'), new GifId($request->validated('GIF_ID')), new FavoriteAlias($request->validated('ALIAS')));

        return $transaction->execute($entry, $request);
    }
}
