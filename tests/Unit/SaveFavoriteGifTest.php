<?php

namespace Tests\Unit;

use App\Application\Favorites\PreparedFavorite;
use App\Application\Favorites\SaveFavoriteGif;
use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Favorites\FavoriteAlreadyExists;
use App\Domain\Favorites\FavoriteGif;
use App\Domain\Favorites\FavoriteGifRepository;
use App\Domain\Favorites\IncorrectFavoriteOwner;
use App\Domain\Gifs\CatalogTimeout;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\Gif;
use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\GifNotFound;
use App\Domain\Gifs\InvalidCatalogResponse;
use PHPUnit\Framework\TestCase;

final class SaveFavoriteGifTest extends TestCase
{
    public function test_preparation_authorizes_before_catalog_and_does_not_write(): void
    {
        $id = new GifId(' AbC 密 ');
        $alias = new FavoriteAlias(' Mi 密 ');
        $catalog = $this->createMock(GifCatalog::class);
        $catalog->expects(self::once())->method('findById')->with(self::identicalTo($id))->willReturn(new Gif($id->value, '', 'https://giphy.com/a', 'https://giphy.com/a.gif'));
        $repo = $this->createMock(FavoriteGifRepository::class);
        $favorite = new FavoriteGif(1, 1, $id, $alias, new \DateTimeImmutable, new \DateTimeImmutable);
        $repo->expects(self::once())->method('insert')->with(1, self::identicalTo($id), self::identicalTo($alias))->willReturn($favorite);
        $save = new SaveFavoriteGif($catalog, $repo);
        try {
            $save->prepare(1, 2, $id, $alias);
            self::fail();
        } catch (IncorrectFavoriteOwner) {
        }
        $entry = $save->prepare(1, 1, $id, $alias);
        self::assertSame(' Mi 密 ', $entry->alias->value);
        self::assertSame(' AbC 密 ', $entry->gifId->value);
        self::assertSame($favorite, $save->persist($entry));
    }

    public function test_absence_and_provider_failures_do_not_write(): void
    {
        foreach ([null, new CatalogTimeout, new CatalogUnavailable, new InvalidCatalogResponse] as $error) {
            $catalog = $this->createMock(GifCatalog::class);
            $method = $catalog->expects(self::once())->method('findById');
            if ($error) {
                $method->willThrowException($error);
            } else {
                $method->willReturn(null);
            }
            $repo = $this->createMock(FavoriteGifRepository::class);
            $repo->expects(self::never())->method('insert');
            try {
                (new SaveFavoriteGif($catalog, $repo))->prepare(1, 1, new GifId('a'), new FavoriteAlias('a'));
                self::fail();
            } catch (\RuntimeException $caught) {
                self::assertInstanceOf($error ? $error::class : GifNotFound::class, $caught);
            }
        }
    }

    public function test_repository_conflict_and_infrastructure_errors_propagate_unchanged(): void
    {
        foreach ([new FavoriteAlreadyExists, new \RuntimeException('repository failure')] as $error) {
            $catalog = $this->createMock(GifCatalog::class);
            $catalog->expects(self::never())->method('findById');
            $repo = $this->createMock(FavoriteGifRepository::class);
            $entry = new PreparedFavorite(1, new GifId('AbC'), new FavoriteAlias('alias'));
            $repo->expects(self::once())->method('insert')->with(1, $entry->gifId, $entry->alias)->willThrowException($error);
            try {
                (new SaveFavoriteGif($catalog, $repo))->persist($entry);
                self::fail('Expected repository error.');
            } catch (\Throwable $actual) {
                self::assertSame($error, $actual);
            }
        }
    }

    public function test_alias_invariants_and_exact_unicode_boundaries(): void
    {
        foreach ([' Mi 密 ', str_repeat('密', 100)] as $text) {
            self::assertSame($text, (new FavoriteAlias($text))->value);
        }
        foreach (['', ' ', "\u{00a0}", str_repeat('密', 101), "\xff"] as $text) {
            try {
                new FavoriteAlias($text);
                self::fail();
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
