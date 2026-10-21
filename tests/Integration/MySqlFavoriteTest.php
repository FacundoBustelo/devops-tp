<?php

namespace Tests\Integration;

use App\Domain\Favorites\FavoriteAlias;
use App\Domain\Favorites\FavoriteAlreadyExists;
use App\Domain\Favorites\FavoriteGifRepository;
use App\Domain\Gifs\GifId;
use App\Infrastructure\Audit\ApiInteraction;
use App\Infrastructure\Favorites\FavoriteGifModel;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MySqlFavoriteTest extends TestCase
{
    use DatabaseMigrations;

    protected bool $requiresMySql = true;

    public function test_exact_unique_constraint_fk_and_physical_delete_restriction(): void
    {
        $user = User::factory()->create();
        $repo = app(FavoriteGifRepository::class);
        foreach (['AbC', 'abc', 'AbC ', '密', '密 ', 'é', "e\u{0301}"] as $id) {
            $repo->insert($user->id, new GifId($id), new FavoriteAlias('original'));
        }
        self::assertSame(7, FavoriteGifModel::count());
        try {
            $repo->insert($user->id, new GifId('AbC'), new FavoriteAlias('replacement'));
            self::fail();
        } catch (FavoriteAlreadyExists) {
        }
        self::assertSame('original', FavoriteGifModel::where('gif_id', 'AbC')->sole()->alias);
        try {
            $repo->insert($user->id + 1, new GifId('a'), new FavoriteAlias('a'));
            self::fail();
        } catch (QueryException $e) {
            self::assertSame(1452, $e->errorInfo[1]);
        }
        $user->delete();
        self::assertSame(7, FavoriteGifModel::count());
        try {
            $user->forceDelete();
            self::fail();
        } catch (QueryException $e) {
            self::assertSame(1451, $e->errorInfo[1]);
        }
        FavoriteGifModel::where('gif_id', '!=', 'AbC')->delete();
        DB::statement('ALTER TABLE favorite_gifs ADD CONSTRAINT favorite_alias_test_unique UNIQUE (alias)');
        try {
            $repo->insert($user->id, new GifId('new'), new FavoriteAlias('original'));
            self::fail();
        } catch (QueryException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function test_two_independent_processes_create_one_favorite_and_two_final_audits(): void
    {
        $user = User::factory()->create();
        $barrier = sys_get_temp_dir().'/prex-favorite-'.bin2hex(random_bytes(8));
        mkdir($barrier, 0700);
        $processes = [];
        try {
            foreach (['first', 'second'] as $label) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/favorite-worker.php'), (string) $user->id, $barrier, $label], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'prex_audit_test', 'DB_URL' => '', 'CACHE_STORE' => 'array']);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 12;
            while (! file_exists($barrier.'/first') || ! file_exists($barrier.'/second')) {
                foreach ($processes as $p) {
                    if (! $p->isRunning()) {
                        self::fail($p->getErrorOutput().$p->getOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    self::fail('Workers did not reach catalog barrier.');
                }
                usleep(20000);
            }
            touch($barrier.'/go');
            $statuses = [];
            foreach ($processes as $p) {
                $p->wait();
                self::assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $result = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
                if ($result['status'] === 409) {
                    self::assertSame(['message' => 'El favorito ya existe.'], $result['body']);
                }
            }
            sort($statuses);
            self::assertSame([201, 409], $statuses);
            self::assertSame(1, FavoriteGifModel::count());
            self::assertSame(2, ApiInteraction::count());
            self::assertSame([201, 409], ApiInteraction::orderBy('status')->pluck('status')->all());
            self::assertSame(2, ApiInteraction::where('user_id', $user->id)->count());
            self::assertSame(2, ApiInteraction::distinct()->count('request_id'));
            self::assertSame(FavoriteGifModel::sole()->alias, ApiInteraction::where('status', 201)->sole()->response_data['data']['alias']);
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            } rmdir($barrier);
        }
    }
}
