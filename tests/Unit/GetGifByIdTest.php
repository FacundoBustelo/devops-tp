<?php

namespace Tests\Unit;

use App\Application\Gifs\GetGifById;
use App\Domain\Gifs\CatalogTimeout;
use App\Domain\Gifs\CatalogUnavailable;
use App\Domain\Gifs\Gif;
use App\Domain\Gifs\GifCatalog;
use App\Domain\Gifs\GifId;
use App\Domain\Gifs\GifNotFound;
use App\Domain\Gifs\InvalidCatalogResponse;
use PHPUnit\Framework\TestCase;

final class GetGifByIdTest extends TestCase
{
    public function test_ids_preserve_text_and_enforce_utf8_character_boundaries(): void
    {
        foreach (['0', '0001', 'AbC', ' a ', '密', '.', '..', str_repeat('密', 128)] as $value) {
            self::assertSame($value, (new GifId($value))->value);
        }
        foreach (['', '   ', "\t\n", "\u{00a0}", "\u{2003}", str_repeat('密', 129), "\xff"] as $value) {
            try {
                new GifId($value);
                self::fail('Invalid ID accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_exact_delegation_absence_and_unchanged_external_errors(): void
    {
        $catalog = $this->createMock(GifCatalog::class);
        $id = new GifId(' 000AbC ');
        $gif = new Gif($id->value, '', 'https://giphy.com', 'https://media.giphy.com/a.gif');
        $errors = [new CatalogUnavailable, new CatalogTimeout, new InvalidCatalogResponse];
        $catalog->expects(self::exactly(5))->method('findById')->with(self::identicalTo($id))
            ->willReturnCallback(function () use ($gif, $errors) {
                static $call = 0;

                return match ($call++) {
                    0 => $gif,
                    1 => null,
                    default => throw $errors[$call - 3],
                };
            });
        $get = new GetGifById($catalog);
        self::assertSame($gif, $get->execute($id));
        try {
            $get->execute($id);
            self::fail('Absence accepted');
        } catch (GifNotFound) {
            self::assertTrue(true);
        }
        foreach ($errors as $error) {
            try {
                $get->execute($id);
                self::fail('Error swallowed');
            } catch (\RuntimeException $caught) {
                self::assertSame($error, $caught);
            }
        }
    }
}
