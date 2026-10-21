<?php

namespace App\Domain\Gifs;

final readonly class GifId
{
    public function __construct(public string $value)
    {
        if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 128 || preg_match('/^\s*$/u', $value)) {
            throw new \InvalidArgumentException('Invalid GIF ID.');
        }
    }
}
