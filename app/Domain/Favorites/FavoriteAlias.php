<?php

namespace App\Domain\Favorites;

final readonly class FavoriteAlias
{
    public function __construct(public string $value)
    {
        if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 100 || preg_match('/^\s*$/u', $value)) {
            throw new \InvalidArgumentException('Invalid favorite alias.');
        }
    }
}
