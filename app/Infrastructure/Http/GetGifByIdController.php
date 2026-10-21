<?php

namespace App\Infrastructure\Http;

use App\Application\Gifs\GetGifById;
use App\Domain\Gifs\GifId;
use Illuminate\Http\JsonResponse;

final class GetGifByIdController
{
    public function __invoke(GetGifByIdRequest $request, GetGifById $get): JsonResponse
    {
        return response()->json(['data' => GifRepresentation::data($get->execute(new GifId($request->validated('id'))))]);
    }
}
