<?php

namespace Tests\Feature;

use App\Application\Audit\Redactor;
use App\Domain\Audit\Interaction;
use App\Domain\Audit\InteractionRepository;
use App\Infrastructure\Audit\ApiInteraction;
use App\Infrastructure\Audit\EloquentInteractionRepository;
use App\Infrastructure\Favorites\FavoriteGifModel;
use App\Models\User;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Assert;
use Tests\Support\OfflineHttp;
use Tests\TestCase;

final class SaveFavoriteGifTest extends TestCase
{
    use DatabaseMigrations;

    private function catalog(): void
    {
        Http::fake(function ($r) {
            self::assertSame(0, DB::transactionLevel());

            return Http::response(GiphyFindByIdTest::payload(rawurldecode(basename(parse_url($r->url(), PHP_URL_PATH)))));
        });
    }

    private function send(User $user, array $extra = [])
    {
        return $this->call('POST', '/api/v1/favorites', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(array_replace(['GIF_ID' => 'AbC123', 'ALIAS' => '  Mi 密 gato  ', 'USER_ID' => $user->id], $extra), JSON_PRESERVE_ZERO_FRACTION));
    }

    private function audited($response, ?int $actor): void
    {
        $row = ApiInteraction::latest('id')->firstOrFail();
        self::assertSame($actor, $row->user_id);
        self::assertSame($response->status(), $row->status);
        self::assertSame('api.v1.favorites.store', $row->service);
        self::assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        self::assertTrue(Str::isUuid($row->request_id));
        self::assertSame('127.0.0.1', $row->ip);
        self::assertEquals(app(Redactor::class)->sanitize($response->json()), $row->response_data);
    }

    public function test_creation_conflict_exact_text_and_distinct_owners(): void
    {
        $this->catalog();
        $user = User::factory()->create();
        Passport::actingAs($user);
        $r = $this->send($user, ['password' => 'external-secret'])->assertCreated();
        $this->audited($r, $user->id);
        self::assertSame(['id', 'user_id', 'gif_id', 'alias', 'created_at', 'updated_at'], array_keys($r->json('data')));
        self::assertSame('  Mi 密 gato  ', $r->json('data.alias'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $r->json('data.created_at'));
        self::assertSame('[REDACTED]', ApiInteraction::sole()->request_data['body']['password']);
        $r = $this->send($user, ['ALIAS' => 'replacement'])->assertConflict()->assertExactJson(['message' => 'El favorito ya existe.']);
        $this->audited($r, $user->id);
        self::assertSame('  Mi 密 gato  ', FavoriteGifModel::sole()->alias);
        foreach (['abc123', 'AbC123 ', ' 密 + % '] as $id) {
            $this->send($user, ['GIF_ID' => $id])->assertCreated();
        }
        $other = User::factory()->create();
        Passport::actingAs($other);
        $this->send($other, ['USER_ID' => '000'.$other->id])->assertCreated();
        self::assertSame(5, FavoriteGifModel::count());
        self::assertSame(6, ApiInteraction::count());
        Http::assertSentCount(6);
        $user->delete();
        self::assertSame(4, FavoriteGifModel::where('user_id', $user->id)->count());
        self::assertSame(5, ApiInteraction::where('user_id', $user->id)->count());
    }

    public function test_authentication_ownership_and_json_only_strict_validation(): void
    {
        $this->catalog();
        $user = User::factory()->create();
        $r = $this->send($user)->assertUnauthorized();
        $this->audited($r, null);
        Passport::actingAs($user);
        $r = $this->send($user, ['USER_ID' => $user->id + 1])->assertForbidden();
        $this->audited($r, $user->id);
        $cases = ['USER_ID' => [true, false, 1.0, 1.5, '1e0', '+1', ' 1', 0, -1, [], null, '9223372036854775808'], 'ALIAS' => ['', ' ', "\u{00a0}", str_repeat('密', 101), [], true], 'GIF_ID' => ['', ' ', str_repeat('密', 129), 12, []]];
        $count = 2;
        foreach ($cases as $field => $values) {
            foreach ($values as $value) {
                $r = $this->send($user, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->audited($r, $user->id);
                $count++;
            }
        }
        $this->post('/api/v1/favorites?GIF_ID=AbC123&ALIAS=a&USER_ID='.$user->id, ['GIF_ID' => 'AbC123', 'ALIAS' => 'a', 'USER_ID' => $user->id])->assertUnprocessable();
        self::assertSame($count + 1, ApiInteraction::count());
        self::assertSame(0, FavoriteGifModel::count());
        Http::assertNothingSent();
    }

    public function test_scientific_json_number_is_rejected_and_response_keeps_alias_while_audit_sanitizes_it(): void
    {
        $this->catalog();
        $user = User::factory()->create();
        Passport::actingAs($user);
        $this->call('POST', '/api/v1/favorites', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"GIF_ID":"AbC123","ALIAS":"a","USER_ID":1e0}')
            ->assertUnprocessable()->assertJsonValidationErrors('USER_ID');
        Http::assertNothingSent();
        $r = $this->send($user, ['ALIAS' => 'Bearer secret-token'])->assertCreated()->assertJsonPath('data.alias', 'Bearer secret-token');
        $this->audited($r, $user->id);
        self::assertSame('Bearer secret-token', FavoriteGifModel::sole()->alias);
        self::assertStringNotContainsString('secret-token', json_encode(ApiInteraction::latest('id')->first()->response_data));
        self::assertSame(2, ApiInteraction::count());
    }

    public function test_missing_gif_and_provider_errors_leave_only_final_error_audit(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        foreach ([404, 502, 503, 504] as $status) {
            OfflineHttp::reset();
            Http::fake(fn () => match ($status) {
                404 => Http::response(['data' => [], 'meta' => ['status' => 404]], 404),
                502 => Http::response(['data' => null]),
                503 => Http::response([], 503),
                504 => throw new ConnectionException('secret', 0, new NetworkTimeoutException('secret', new Request('GET', 'https://api.giphy.com'))),
            });
            $r = $this->send($user)->assertStatus($status);
            $this->audited($r, $user->id);
            if ($status !== 504) {
                Http::assertSentCount(1);
            }
        }
        self::assertSame(0, FavoriteGifModel::count());
        self::assertSame(4, ApiInteraction::count());
    }

    public function test_failures_before_and_after_audit_insertion_roll_back_and_audit_outside_transaction(): void
    {
        $this->catalog();
        $user = User::factory()->create();
        Passport::actingAs($user);
        foreach ([false, true] as $afterInsert) {
            app()->bind(InteractionRepository::class, fn () => new class($afterInsert) implements InteractionRepository
            {
                public function __construct(private bool $afterInsert) {}

                public function persist(Interaction $interaction): void
                {
                    if ($interaction->status === 201) {
                        Assert::assertSame(1, DB::transactionLevel());
                        if ($this->afterInsert) {
                            (new EloquentInteractionRepository)->persist($interaction);
                        }
                        throw new \RuntimeException('injected SQL secret');
                    }
                    Assert::assertSame(0, DB::transactionLevel());
                    (new EloquentInteractionRepository)->persist($interaction);
                }
            });
            $r = $this->send($user)->assertStatus(500)->assertExactJson(['message' => 'Error del servidor.']);
            $this->audited($r, $user->id);
            self::assertSame(0, FavoriteGifModel::count());
            self::assertSame(0, ApiInteraction::where('status', 201)->count());
        }
        self::assertSame(2, ApiInteraction::count());
    }
}
