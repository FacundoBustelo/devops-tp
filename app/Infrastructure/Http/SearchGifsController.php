<?php

namespace App\Infrastructure\Http;

use App\Application\Gifs\SearchGifs;
use App\Domain\Gifs\SearchCriteria;
use Illuminate\Http\JsonResponse;

final class SearchGifsController
{
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
