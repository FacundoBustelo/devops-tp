<?php

namespace App\Infrastructure\Http;

use App\Application\Gifs\GetGifById;
use App\Domain\Gifs\GifId;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class GetGifByIdController
{
    #[OA\Get(
        path: '/api/v1/gifs/{id}', operationId: 'getGifById', tags: ['GIFs'],
        summary: 'Consultar un GIF por ID',
        description: 'Solo lee el ID de la ruta, con comparación exacta. Codificar el segmento en la URL; las barras, incluso codificadas, no pertenecen a esta ruta.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(ref: '#/components/schemas/GifId'))],
        responses: [
            new OA\Response(response: 200, description: 'GIF encontrado.', headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')], content: new OA\JsonContent(ref: '#/components/schemas/GifResult')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFound'),
            new OA\Response(response: 422, ref: '#/components/responses/Validation'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
            new OA\Response(response: 502, ref: '#/components/responses/BadGateway'),
            new OA\Response(response: 503, ref: '#/components/responses/Unavailable'),
            new OA\Response(response: 504, ref: '#/components/responses/Timeout'),
        ],
    )]
    public function __invoke(GetGifByIdRequest $request, GetGifById $get): JsonResponse
    {
        return response()->json(['data' => GifRepresentation::data($get->execute(new GifId($request->validated('id'))))]);
    }
}
