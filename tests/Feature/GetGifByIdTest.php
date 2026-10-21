<?php

namespace Tests\Feature;

use App\Application\Audit\Redactor;
use App\Infrastructure\Audit\ApiInteraction;
use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\Support\OfflineHttp;
use Tests\TestCase;

final class GetGifByIdTest extends TestCase
{
    use DatabaseMigrations;

    private function fake($callback = null): void
    {
        OfflineHttp::reset();
        Http::fake($callback);
    }

    private function audited(TestResponse $response, ?int $actor, string $id): void
    {
        $row = ApiInteraction::latest('id')->firstOrFail();
        self::assertSame($actor, $row->user_id);
        self::assertSame('api.v1.gifs.show', $row->service);
        self::assertSame($response->status(), $row->status);
        self::assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        self::assertTrue(Str::isUuid($row->request_id));
        self::assertSame('127.0.0.1', $row->ip);
        self::assertEquals(app(Redactor::class)->sanitize($response->json()), $row->response_data);
        self::assertSame($id, $row->request_data['route']['id']);
        self::assertStringNotContainsString('external-secret', json_encode([$row->request_data, $row->response_data]));
    }

    public function test_authentication_route_only_validation_and_router_boundaries(): void
    {
        $this->fake();
        $r = $this->get('/api/v1/gifs/AbC123')->assertUnauthorized();
        $this->audited($r, null, 'AbC123');
        $user = User::factory()->create();
        Passport::actingAs($user);
        $invalid = [' ', "\u{00a0}", str_repeat('密', 129)];
        foreach ($invalid as $id) {
            $r = $this->call('GET', '/api/v1/gifs/'.rawurlencode($id).'?id=valid', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"valid"}')
                ->assertUnprocessable()->assertJsonValidationErrors('id');
            $this->audited($r, $user->id, $id);
        }
        self::assertSame(4, ApiInteraction::count());
        $this->get('/api/v1/gifs/a/b')->assertNotFound();
        $this->get('/api/v1/gifs/a%2Fb')->assertNotFound();
        $this->get('/api/v1/gifs')->assertUnprocessable()->assertJsonValidationErrors('QUERY');
        Http::assertNothingSent();
    }

    public function test_original_ids_common_representation_and_sanitized_audit_once(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        foreach (['000AbC', ' 密 + & % ', str_repeat('密', 128)] as $id) {
            $this->fake(['*' => Http::response(GiphyFindByIdTest::payload($id))]);
            $r = $this->call('GET', '/api/v1/gifs/'.rawurlencode($id).'?id=other&api_key=external-secret', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"body","password":"external-secret"}')
                ->assertOk()->assertExactJson(['data' => [
                    'id' => $id, 'title' => '', 'url' => 'https://giphy.com/gifs/AbC123', 'image_url' => 'https://media.giphy.com/a.gif?x=1&y=2',
                ]]);
            $this->audited($r, $user->id, $id);
            Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/v1/gifs/'.rawurlencode($id));
            Http::assertSentCount(1);
        }
        self::assertSame(3, ApiInteraction::count());
    }

    public function test_not_found_and_external_errors_are_audited_once_without_technical_reports(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        Log::spy();
        $cases = [
            [404, ['data' => [], 'meta' => ['status' => 404]], 404],
            [404, ['data' => null, 'meta' => ['status' => 404]], 502],
            [200, ['data' => []], 502],
            [200, GiphyFindByIdTest::payload('different'), 502],
            [302, 'external-secret', 502], [401, 'external-secret', 503],
            [403, 'external-secret', 503], [429, 'external-secret', 503], [503, 'external-secret', 503],
        ];
        foreach ($cases as [$status, $body, $expected]) {
            $this->fake(['*' => Http::response($body, $status)]);
            $r = $this->get('/api/v1/gifs/AbC123')->assertStatus($expected)
                ->assertExactJson(['message' => $expected === 404 ? 'Recurso no encontrado.' : 'Error del servidor.']);
            $this->audited($r, $user->id, 'AbC123');
            Http::assertSentCount(1);
        }
        foreach ([false => 503, true => 504] as $timeout => $expected) {
            $this->fake(fn () => throw new ConnectionException('external-secret', 0, $timeout
                ? new NetworkTimeoutException('external-secret', new Request('GET', 'https://api.giphy.com'))
                : new ConnectException('external-secret', new Request('GET', 'https://api.giphy.com'))));
            $r = $this->get('/api/v1/gifs/AbC123')->assertStatus($expected)->assertExactJson(['message' => 'Error del servidor.']);
            $this->audited($r, $user->id, 'AbC123');
        }
        $this->fake();
        config(['giphy.api_key' => '']);
        $r = $this->get('/api/v1/gifs/AbC123')->assertStatus(503);
        $this->audited($r, $user->id, 'AbC123');
        Http::assertNothingSent();
        self::assertSame(count($cases) + 3, ApiInteraction::count());
        Log::shouldNotHaveReceived('error');
    }
}
