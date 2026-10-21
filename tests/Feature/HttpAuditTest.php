<?php

namespace Tests\Feature;

use App\Application\Audit\RecordInteraction;
use App\Application\Audit\Redactor;
use App\Domain\Audit\Interaction;
use App\Domain\Audit\InteractionRepository;
use App\Infrastructure\Audit\ApiInteraction;
use App\Infrastructure\Audit\AuditContext;
use App\Infrastructure\Audit\AuditFallback;
use App\Infrastructure\Audit\HttpInteractionCapture;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\MutateFinalResponse;
use Tests\TestCase;

final class HttpAuditTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
        Route::post('/api/_test/echo/{id}', function (Request $request) {
            return response()->json(['value' => $request->input('value'), 'access_token' => 'response-secret'], 201);
        })->name('test.echo');
        Route::get('/api/_test/public', fn () => response()->json(['public' => true]));
        Route::get('/api/_test/unnamed/{id}', fn () => response()->json(['ok' => true]));
        Route::post('/api/_test/validation', fn (Request $r) => $r->validate(['required' => 'required']));
        Route::get('/api/_test/exception', fn () => throw new RuntimeException('SQL password=internal-secret'));
        Route::get('/api/_test/error/{status}', function ($status) {
            abort((int) $status, 'SQL password=internal-secret', ['Retry-After' => '15']);
        });
        Route::get('/api/_test/text', fn () => response('password=opaque-secret'));
        Route::get('/api/_test/invalid', fn () => response('{invalid password=opaque-secret', 200, ['Content-Type' => 'application/json']));
        Route::get('/api/_test/stream', fn () => response()->stream(fn () => print ('stream-secret')));
        Route::get('/web-test', fn () => 'web');
        Route::post('/api/_test/verified', function (Request $r) {
            $r->attributes->get(AuditContext::class)->assignVerifiedActor(User::firstOrFail()->id);

            return response()->json(['ok' => true]);
        });
        Route::get('/api/_test/transaction/{outcome}', function (Request $r, $outcome) {
            $context = $r->attributes->get(AuditContext::class);

            return DB::transaction(function () use ($r, $outcome, $context) {
                $response = response()->json(['ok' => true], 201);
                app(RecordInteraction::class)->strictly(app(HttpInteractionCapture::class)->interaction($r, $response, $context));
                DB::afterCommit(fn () => $context->markCommitted());
                if ($outcome === 'rollback') {
                    throw new RuntimeException('rollback-secret');
                }

                return $response;
            });
        });
    }

    public function test_final_response_sanitized_input_uuid_and_no_client_identity(): void
    {
        $response = $this->withHeaders(['X-Request-ID' => 'client-id', 'Authorization' => 'Bearer header-secret', 'Cookie' => 'cookie-secret'])
            ->postJson('/api/_test/echo/AbC?QUERY=cats&api_key=query-secret', ['value' => '  public  ', 'password' => 'body-secret', 'USER_ID' => 900, 'email' => 'fake@example.test']);
        $response->assertCreated()->assertJsonPath('value', 'public')->assertJsonPath('access_token', 'response-secret');
        $row = ApiInteraction::sole();
        self::assertTrue(Str::isUuid($row->request_id));
        self::assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        self::assertNull($row->user_id);
        self::assertSame('test.echo', $row->service);
        self::assertSame('POST', $row->method);
        self::assertSame(['id' => 'AbC'], $row->request_data['route']);
        self::assertSame('  public  ', $row->request_data['body']['value']);
        self::assertSame('cats', $row->request_data['query']['QUERY']);
        self::assertSame(201, $row->status);
        self::assertSame(app(Redactor::class)->sanitize($response->json()), $row->response_data);
        self::assertStringNotContainsString('secret', json_encode([$row->request_data, $row->response_data]));
        $second = $this->get('/api/_test/public?QUERY=dogs');
        self::assertNotSame($row->request_id, $second->headers->get('X-Request-ID'));
        $get = ApiInteraction::latest('id')->first();
        self::assertSame([], $get->request_data['body']);
        self::assertSame('dogs', $get->request_data['query']['QUERY']);
    }

    public static function debugModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('debugModes')]
    public function test_errors_without_accept_are_json_and_match_audit_even_in_debug(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $responses = [
            $this->post('/api/_test/validation'), $this->get('/api/_test/exception'),
            $this->get('/api'), $this->get('/api/unknown/secret-url?token=url-secret'),
            $this->post('/api/_test/public'),
        ];
        foreach ([401, 403, 429, 502, 503, 504, 409] as $status) {
            $responses[] = $this->get('/api/_test/error/'.$status);
        }
        $rows = ApiInteraction::orderBy('id')->get();
        foreach ($responses as $index => $response) {
            self::assertStringContainsString('application/json', $response->headers->get('Content-Type'));
            self::assertSame($response->status(), $rows[$index]->status);
            self::assertEquals($response->json(), $rows[$index]->response_data);
            self::assertStringNotContainsString('internal-secret', $response->getContent());
            self::assertStringNotContainsString('trace', $response->getContent());
            self::assertNull($rows[$index]->user_id);
            self::assertTrue(Str::isUuid($rows[$index]->request_id));
            self::assertSame($response->headers->get('X-Request-ID'), $rows[$index]->request_id);
            self::assertSame('127.0.0.1', $rows[$index]->ip);
            self::assertNotEmpty($rows[$index]->service);
            self::assertStringNotContainsString('secret', json_encode([$rows[$index]->request_data, $rows[$index]->response_data]));
        }
        self::assertCount(count($responses), $rows);
        self::assertSame(count($responses), $rows->pluck('request_id')->unique()->count());
        $responses[0]->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $responses[1]->assertStatus(500)->assertExactJson(['message' => 'Error del servidor.']);
        $responses[4]->assertStatus(405)->assertHeader('Allow');
        $responses[7]->assertStatus(429)->assertHeader('Retry-After', '15');
        self::assertSame('api.unmatched', $rows[3]->service);
    }

    public function test_audit_observes_response_changes_from_other_middleware(): void
    {
        Route::get('/api/_test/mutated', fn () => response()->json(['initial' => true]))->middleware(MutateFinalResponse::class);
        $response = $this->get('/api/_test/mutated')->assertStatus(202)->assertJsonPath('token', 'middleware-secret');
        $row = ApiInteraction::sole();
        self::assertSame(202, $row->status);
        self::assertEquals(app(Redactor::class)->sanitize($response->json()), $row->response_data);
    }

    public function test_identity_is_authenticated_or_explicitly_verified_only(): void
    {
        $user = User::factory()->create();
        $this->post('/api/_test/verified', ['USER_ID' => 999])->assertOk();
        self::assertSame($user->id, ApiInteraction::sole()->user_id);
        $this->actingAs($user)->get('/api/_test/public?USER_ID=999')->assertOk();
        self::assertSame($user->id, ApiInteraction::latest('id')->first()->user_id);
        $user->delete();
        self::assertSame(2, ApiInteraction::where('user_id', $user->id)->count());
        $user->forceDelete();
        self::assertSame(2, ApiInteraction::whereNull('user_id')->count());
    }

    public function test_scope_and_opaque_body_limits(): void
    {
        $this->get('/up')->assertOk()->assertHeaderMissing('X-Request-ID');
        $this->get('/web-test')->assertOk()->assertHeaderMissing('X-Request-ID');
        $this->get('/api-like')->assertNotFound()->assertHeaderMissing('X-Request-ID');
        self::assertSame(0, ApiInteraction::count());
        foreach (['text', 'invalid', 'stream'] as $kind) {
            $this->get('/api/_test/'.$kind)->assertOk();
            self::assertSame(Redactor::OMITTED, ApiInteraction::latest('id')->first()->response_data);
        }
        $this->get('/api/_test/unnamed/AbC')->assertOk();
        self::assertSame('api/_test/unnamed/{id}', ApiInteraction::latest('id')->first()->service);
        $this->call('POST', '/api/_test/echo/AbC', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid password=secret');
        self::assertSame(Redactor::OMITTED, ApiInteraction::latest('id')->first()->request_data['body']);
    }

    public function test_proxy_configuration_ignores_spoofed_host_and_untrusted_forwarding(): void
    {
        foreach ([[], ['*', '**', 'REMOTE_ADDR', '0.0.0.0/0'], ['10.1.0.0/16']] as $proxies) {
            config(['audit.trusted_proxies' => $proxies]);
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeaders(['Host' => 'fake.on-vapor.com', 'X-Forwarded-For' => '203.0.113.7'])->get('/api/_test/public')->assertOk();
            self::assertSame($proxies === ['10.1.0.0/16'] ? '203.0.113.7' : '10.1.2.3', ApiInteraction::latest('id')->first()->ip);
        }
        config(['audit.trusted_proxies' => ['10.2.3.4']]);
        $this->get('/api/_test/public')->assertOk();
        self::assertSame('10.1.2.3', ApiInteraction::latest('id')->first()->ip);
    }

    public function test_repository_failure_preserves_response_and_logs_only_safe_metadata(): void
    {
        $this->failingRepository();
        Log::shouldReceive('channel')->once()->with('audit')->andReturn($logger = \Mockery::mock());
        $logger->shouldReceive('error')->once()->with('api_audit_persistence_failed', \Mockery::on(function ($event) {
            self::assertSame(['event', 'request_id', 'status'], array_keys($event));
            self::assertTrue(Str::isUuid($event['request_id']));
            self::assertStringNotContainsString('secret', json_encode($event));

            return true;
        }));
        $this->postJson('/api/_test/echo/AbC', ['password' => 'secret', 'value' => 'ok'])->assertCreated()->assertJsonPath('value', 'ok');
        self::assertSame(0, ApiInteraction::count());
    }

    public function test_both_fallback_failures_are_contained_without_retry(): void
    {
        $this->failingRepository();
        $fallback = new class extends AuditFallback
        {
            public int $attempts = 0;

            public ?string $id = null;

            protected function emit(array $event): void
            {
                $this->attempts++;
                throw new RuntimeException('fallback-secret');
            }

            protected function minimal(string $requestId): void
            {
                $this->attempts++;
                $this->id = $requestId;
                throw new RuntimeException('stderr-secret');
            }
        };
        $this->app->instance(AuditFallback::class, $fallback);
        $response = $this->get('/api/_test/public')->assertOk()->assertExactJson(['public' => true]);
        self::assertSame(2, $fallback->attempts);
        self::assertSame($response->headers->get('X-Request-ID'), $fallback->id);
    }

    public function test_strict_persistence_propagates_the_repository_error(): void
    {
        $this->failingRepository();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQL bindings password=repository-secret');
        app(RecordInteraction::class)->strictly(new Interaction((string) Str::uuid(), null, 'GET', 'test', [], 200, [], null, new \DateTimeImmutable));
    }

    public function test_commit_records_once_and_rollback_audits_final_error(): void
    {
        $this->get('/api/_test/transaction/commit')->assertCreated();
        self::assertSame(1, ApiInteraction::count());
        self::assertSame(201, ApiInteraction::sole()->status);
        $response = $this->get('/api/_test/transaction/rollback')->assertStatus(500);
        self::assertSame(2, ApiInteraction::count());
        $row = ApiInteraction::latest('id')->first();
        self::assertSame(500, $row->status);
        self::assertSame($response->json(), $row->response_data);
        self::assertSame($response->headers->get('X-Request-ID'), $row->request_id);
        self::assertSame(0, DB::transactionLevel());
    }

    private function failingRepository(): void
    {
        $this->app->bind(InteractionRepository::class, fn () => new class implements InteractionRepository
        {
            public function persist(Interaction $interaction): void
            {
                throw new RuntimeException('SQL bindings password=repository-secret');
            }
        });
    }
}
