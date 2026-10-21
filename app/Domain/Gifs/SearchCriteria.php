<?php

namespace App\Domain\Gifs;

final readonly class SearchCriteria
{
    public function __construct(public string $query, public int $limit = 25, public int $offset = 0)
    {
        if (! mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 50 || preg_match('/^\s*$/u', $query) || $limit < 1 || $limit > 50 || $offset < 0 || $offset > 4999) {
            throw new \InvalidArgumentException('Invalid search criteria.');
        }
    }
}
