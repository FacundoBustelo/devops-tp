<?php

namespace App\Infrastructure\Http;

use App\Application\Favorites\SaveFavoriteGif;
use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Gifs\GifId;
use App\Infrastructure\Favorites\SaveFavoriteTransaction;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class SaveFavoriteGifController
{
    #[OA\Post(
        path: '/api/v1/favorites', operationId: 'saveFavoriteGif', tags: ['Favoritos'],
        summary: 'Guardar un GIF como favorito',
        description: 'Requiere cuerpo JSON y USER_ID igual al actor autenticado. Consulta GIPHY antes de guardar. El mismo usuario/GIF devuelve 409 y conserva el alias anterior.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FavoriteInput')),
        responses: [
            new OA\Response(response: 201, description: 'Favorito y auditoría guardados en la misma transacción.', headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')], content: new OA\JsonContent(ref: '#/components/schemas/FavoriteResult')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 403, ref: '#/components/responses/Forbidden'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 409, ref: '#/components/responses/Conflict'),
            new OA\Response(response: 422, ref: '#/components/responses/Validation'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
            new OA\Response(response: 502, ref: '#/components/responses/BadGateway'),
            new OA\Response(response: 503, ref: '#/components/responses/Unavailable'),
            new OA\Response(response: 504, ref: '#/components/responses/Timeout'),
        ],
    )]
    public function __invoke(SaveFavoriteGifRequest $request, SaveFavoriteGif $save, SaveFavoriteTransaction $transaction): JsonResponse
    {
        $entry = $save->prepare((int) $request->user()->getAuthIdentifier(), (int) $request->validated('USER_ID'), new GifId($request->validated('GIF_ID')), new FavoriteAlias($request->validated('ALIAS')));

        return $transaction->execute($entry, $request);
    }
}
