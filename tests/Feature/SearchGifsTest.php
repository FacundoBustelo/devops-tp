<?php

namespace Tests\Feature;

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

final class SearchGifsTest extends TestCase
{
    use DatabaseMigrations;

    private function fake($callback = null): void
    {
        OfflineHttp::reset();
        Http::fake($callback);
    }

    private function search(array $query = []): TestResponse
    {
        return $this->get('/api/v1/gifs?'.http_build_query($query));
    }

    private function audited($response, ?int $actor): void
    {
        $row = ApiInteraction::latest('id')->firstOrFail();
        self::assertSame($actor, $row->user_id);
        self::assertSame($response->status(), $row->status);
        self::assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        self::assertTrue(Str::isUuid($row->request_id));
        self::assertSame('127.0.0.1', $row->ip);
        self::assertEquals($response->json(), $row->response_data);
        self::assertSame('api.v1.gifs.search', $row->service);
        self::assertStringNotContainsString('external-secret', json_encode([$row->request_data, $row->response_data]));
    }

    public function test_authentication_validation_query_only_defaults_boundaries_and_no_external_calls(): void
    {
        $this->fake();
        $r = $this->search(['QUERY' => 'cats'])->assertUnauthorized();
        $this->audited($r, null);
        $user = User::factory()->create();
        Passport::actingAs($user);
        $cases = [[], ['QUERY' => ''], ['QUERY' => '   '], ['QUERY' => "\u{00a0}"], ['QUERY' => []], ['QUERY' => str_repeat('密', 51)]];
        foreach (['LIMIT' => ['', ['2'], '1.0', '1e1', '-1', '0', '51', ' 2 ', '+2'], 'OFFSET' => ['', ['2'], '1.0', '-1', '5000']] as $field => $values) {
            foreach ($values as $value) {
                $cases[] = ['QUERY' => 'cats', $field => $value];
            }
        }
        foreach ($cases as $query) {
            $r = $this->search($query)->assertUnprocessable();
            $this->audited($r, $user->id);
        }
        $r = $this->call('GET', '/api/v1/gifs', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['QUERY' => 'body only']))->assertUnprocessable();
        $this->audited($r, $user->id);
        Http::assertNothingSent();
        self::assertSame(count($cases) + 2, ApiInteraction::count());
        foreach ([[], ['LIMIT' => 1], ['LIMIT' => 50, 'OFFSET' => 4999]] as $page) {
            $this->fake(['*' => Http::response(['data' => [], 'pagination' => ['offset' => $page['OFFSET'] ?? 0, 'count' => 0]])]);
            $r = $this->search(['QUERY' => str_repeat('密', 50)] + $page)->assertOk()->assertExactJson(['data' => [], 'pagination' => ['limit' => $page['LIMIT'] ?? 25, 'offset' => $page['OFFSET'] ?? 0, 'count' => 0]]);
            $this->audited($r, $user->id);
        }
    }

    public function test_contract_original_query_order_and_optional_pagination_are_audited_once(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        $payload = GiphyCatalogTest::payload();
        $payload['pagination']['total_count'] = 10;
        $this->fake(['*' => Http::response($payload)]);
        $query = '  密 + & %  ';
        $r = $this->search(['QUERY' => $query])->assertOk()->assertExactJson([
            'data' => [
                ['id' => 'AbC123', 'title' => '', 'url' => 'https://giphy.com/gifs/AbC123', 'image_url' => 'https://media.giphy.com/a.gif?x=1&y=2'],
                ['id' => 'DEF', 'title' => '密', 'url' => 'https://giphy.com/gifs/DEF', 'image_url' => 'https://media.giphy.com/b.gif'],
            ], 'pagination' => ['limit' => 25, 'offset' => 0, 'count' => 2, 'total_count' => 10],
        ]);
        $this->audited($r, $user->id);
        self::assertSame(1, ApiInteraction::count());
        self::assertSame($query, ApiInteraction::sole()->request_data['query']['QUERY']);
        Http::assertSent(fn ($request) => $request['q'] === $query);
    }

    public function test_external_failures_have_sanitized_final_response_audit_and_no_technical_logs(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);
        Log::spy();
        foreach ([302 => 502, 404 => 502, 401 => 503, 403 => 503, 429 => 503, 500 => 503, 200 => 502] as $status => $expected) {
            $this->fake(['*' => Http::response('technical api_key=external-secret', $status)]);
            $r = $this->search(['QUERY' => 'cats'])->assertStatus($expected)->assertExactJson(['message' => 'Error del servidor.']);
            $this->audited($r, $user->id);
            Http::assertSentCount(1);
        }
        foreach ([6 => 503, 28 => 504] as $errno => $expected) {
            $this->fake(fn () => throw new ConnectionException('technical external-secret', 0, ($errno === 28 ? new NetworkTimeoutException('technical secret', new Request('GET', 'https://api.giphy.com')) : new ConnectException('technical secret', new Request('GET', 'https://api.giphy.com')))));
            $r = $this->search(['QUERY' => 'cats'])->assertStatus($expected)->assertExactJson(['message' => 'Error del servidor.']);
            $this->audited($r, $user->id);
        }
        config(['giphy.api_key' => '']);
        $this->fake();
        $r = $this->search(['QUERY' => 'cats'])->assertStatus(503);
        $this->audited($r, $user->id);
        Http::assertNothingSent();
        self::assertSame(10, ApiInteraction::count());
        Log::shouldNotHaveReceived('error');
    }
}
