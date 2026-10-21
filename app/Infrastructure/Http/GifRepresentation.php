<?php

namespace App\Infrastructure\Http;

use App\Domain\Gifs\Gif;

final class GifRepresentation
{
    public static function data(Gif $gif): array
    {
        return ['id' => $gif->id, 'title' => $gif->title, 'url' => $gif->url, 'image_url' => $gif->imageUrl];
    }
}
