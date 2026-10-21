<?php

namespace Tests\Unit;

use App\Application\Gifs\SearchGifs;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\Gif;
use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\SearchCriteria;
use App\Domain\Gifs\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchGifsTest extends TestCase
{
    public function test_exact_delegation_defaults_empty_result_and_errors(): void
    {
        $criteria = new SearchCriteria('  密 + & %  ');
        self::assertSame(25, $criteria->limit);
        self::assertSame(0, $criteria->offset);
        $fake = new class implements GifCatalog
        {
            public ?SearchCriteria $received = null;

            public bool $fail = false;

            public function findById(GifId $id): ?Gif
            {
                return null;
            }

            public function search(SearchCriteria $criteria): SearchResult
            {
                $this->received = $criteria;
                if ($this->fail) {
                    throw new CatalogUnavailable;
                }

                return new SearchResult([], $criteria->limit, $criteria->offset);
            }
        };
        $useCase = new SearchGifs($fake);
        self::assertSame([], $useCase->execute($criteria)->gifs);
        self::assertSame($criteria, $fake->received);
        $fake->fail = true;
        $this->expectException(CatalogUnavailable::class);
        $useCase->execute($criteria);
    }

    public function test_invariants_and_boundaries(): void
    {
        foreach ([[''], ['   '], ["\u{00a0}"], [str_repeat('密', 51)], ['x', 0], ['x', 51], ['x', 25, -1], ['x', 25, 5000]] as $args) {
            try {
                new SearchCriteria(...$args);
                self::fail('Invalid criteria accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(4999, (new SearchCriteria(str_repeat('密', 50), 50, 4999))->offset);
        self::assertSame(1, (new SearchCriteria('x', 1))->limit);
    }
}
