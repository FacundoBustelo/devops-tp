<?php

namespace App\Infrastructure\Http;

use App\Application\Gifs\SearchGifs;
use App\Domain\Gifs\SearchCriteria;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class SearchGifsController
{
    #[OA\Get(
        path: '/api/v1/gifs', operationId: 'searchGifs', tags: ['GIFs'],
        summary: 'Buscar GIFs', description: 'Solo lee parámetros de query string. Una búsqueda sin resultados devuelve 200 con data vacío.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'QUERY', in: 'query', required: true, description: 'Término UTF-8 no blanco; se conservan espacios.', schema: new OA\Schema(type: 'string', minLength: 1, maxLength: 50, pattern: '\\S'), example: 'gatos'),
            new OA\Parameter(name: 'LIMIT', in: 'query', description: 'Entero decimal de dígitos, sin signo ni espacios.', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 25)),
            new OA\Parameter(name: 'OFFSET', in: 'query', description: 'Entero decimal de dígitos, sin signo ni espacios.', schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 4999, default: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Resultados y paginación.', headers: [new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId')], content: new OA\JsonContent(ref: '#/components/schemas/SearchResult')),
            new OA\Response(response: 401, ref: '#/components/responses/Unauthenticated'),
            new OA\Response(response: 422, ref: '#/components/responses/Validation'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
            new OA\Response(response: 502, ref: '#/components/responses/BadGateway'),
            new OA\Response(response: 503, ref: '#/components/responses/Unavailable'),
            new OA\Response(response: 504, ref: '#/components/responses/Timeout'),
        ],
    )]
    public function __invoke(SearchGifsRequest $request, SearchGifs $search): JsonResponse
    {
        $data = $request->validated();
        $result = $search->execute(new SearchCriteria($data['QUERY'], (int) $data['LIMIT'], (int) $data['OFFSET']));
        $pagination = ['limit' => $result->limit, 'offset' => $result->offset, 'count' => count($result->gifs)];
        if ($result->totalCount !== null) {
            $pagination['total_count'] = $result->totalCount;
        }

        return response()->json(['data' => array_map(GifRepresentation::data(...), $result->gifs), 'pagination' => $pagination]);
    }
}
