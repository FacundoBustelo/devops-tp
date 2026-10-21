<?php

namespace App\Domain\Gifs;

final readonly class Gif
{
    public function __construct(public string $id, public string $title, public string $url, public string $imageUrl) {}
}
