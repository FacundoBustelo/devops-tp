<?php

namespace Tests\Feature;

use App\Infrastructure\Audit\ApiInteraction;
use App\Infrastructure\Audit\AuditContext;
use App\Infrastructure\Observability\MetricsStore;
use App\Infrastructure\Observability\RecordHttpRequest;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Prometheus\RenderTextFormat;
use RuntimeException;
use Tests\Fixtures\MutateFinalResponse;
use Tests\TestCase;

final class ObservabilityTest extends TestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/devops-http-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config([
            'observability.enabled' => true,
            'observability.database' => $this->directory.'/metrics.sqlite',
            'logging.channels.http.handler_with.stream' => $this->directory.'/events.jsonl',
        ]);
        app(MetricsStore::class)->initialize();
        Route::get('/api/test/ok/{id}', fn () => response()->json(['token' => 'response-secret']))->name('test.ok');
        Route::get('/api/test/mutated', fn () => response()->json(['ok' => true]))->middleware(MutateFinalResponse::class)->name('test.mutated');
        Route::get('/api/test/failure', fn () => throw new RuntimeException('SQL password=exception-secret https://example.test/?api_key=secret'))->name('test.failure');
        Route::match(['PROPFIND', 'TRACE'], '/api/test/method', fn () => response()->json(['ok' => true]));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_header_audit_and_json_log_share_fresh_uuid_and_allowlisted_fields(): void
    {
        $first = $this->withHeaders(['X-Request-ID' => 'client-secret', 'Authorization' => 'Bearer header-secret', 'Cookie' => 'cookie-secret'])
            ->get('/api/test/ok/private-id?api_key=query-secret&email=private@example.test')->assertOk();
        $second = $this->get('/api/test/ok/different-id?token=secret')->assertOk();
        $events = $this->events();
        self::assertCount(2, $events);
        foreach ([$first, $second] as $i => $response) {
            $record = $events[$i];
            self::assertSame('INFO', $record['level_name']);
            self::assertSame('http_request_completed', $record['message']);
            self::assertSame(['event', 'request_id', 'method', 'route', 'status', 'duration_ms'], array_keys($record['context']));
            $id = $response->headers->get('X-Request-ID');
            self::assertTrue(Str::isUuid($id));
            self::assertSame($id, $record['context']['request_id']);
            self::assertSame(200, ApiInteraction::where('request_id', $id)->sole()->status);
            self::assertGreaterThanOrEqual(0, $record['context']['duration_ms']);
            self::assertStringContainsString('+00:00', $record['datetime']);
        }
        self::assertNotSame($first->headers->get('X-Request-ID'), $second->headers->get('X-Request-ID'));
        $serialized = file_get_contents($this->directory.'/events.jsonl');
        foreach (['secret', 'private-id', 'different-id', 'private@example.test', '127.0.0.1'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
        self::assertStringContainsString('route="test.ok",status="200"} 2', app(MetricsStore::class)->collect());
    }

    public function test_final_response_and_exceptions_are_observed_once_with_safe_levels(): void
    {
        config(['app.debug' => true]);
        $this->get('/api/test/mutated')->assertStatus(202);
        $this->get('/api/unknown/secret?token=secret')->assertNotFound();
        $this->get('/api/test/failure')->assertStatus(500)->assertDontSee('exception-secret');
        $events = $this->events();
        $http = array_values(array_filter($events, fn ($e) => $e['message'] === 'http_request_completed'));
        self::assertSame([202, 404, 500], array_column(array_column($http, 'context'), 'status'));
        self::assertSame(['INFO', 'WARNING', 'ERROR'], array_column($http, 'level_name'));
        $exceptions = array_values(array_filter($events, fn ($e) => $e['message'] === 'application_exception'));
        self::assertCount(1, $exceptions);
        self::assertSame($http[2]['context']['request_id'], $exceptions[0]['context']['request_id']);
        self::assertSame(['event', 'request_id', 'exception_type'], array_keys($exceptions[0]['context']));
        self::assertStringNotContainsString('secret', json_encode($events));
        self::assertSame(3, ApiInteraction::count());
        $metrics = app(MetricsStore::class)->collect();
        self::assertStringContainsString('route="test.failure",status="500"} 1', $metrics);
        self::assertStringContainsString('route="test.mutated",status="202"} 1', $metrics);
    }

    public function test_scope_cardinality_and_unknown_methods(): void
    {
        $before = app(MetricsStore::class)->collect();
        $this->get('/up')->assertOk();
        $this->get('/docs');
        $this->get('/asset.js');
        $this->withServerVariables(['DEVOPS_METRICS_INTERNAL' => '1'])->get('/metrics')->assertOk();
        self::assertSame($before, app(MetricsStore::class)->collect());
        foreach (['one', 'two'] as $id) {
            $this->get('/api/not-found/'.$id.'?q='.$id)->assertNotFound();
        }
        foreach (['PROPFIND', 'TRACE'] as $method) {
            $this->call($method, '/api/test/method')->assertOk();
        }
        $metrics = app(MetricsStore::class)->collect();
        self::assertStringContainsString('method="OTHER",route="api.unmatched",status="200"} 2', $metrics);
        self::assertStringContainsString('method="GET",route="api.unmatched",status="404"} 2', $metrics);
        self::assertStringNotContainsString('not-found', $metrics);
    }

    public function test_scrape_requires_feature_flag_and_non_http_server_marker(): void
    {
        $this->withHeaders(['DEVOPS_METRICS_INTERNAL' => '1', 'X-Devops-Metrics-Internal' => '1', 'X-Forwarded-Port' => '9091'])->get('/metrics')->assertNotFound();
        $this->withServerVariables(['DEVOPS_METRICS_INTERNAL' => '0'])->get('/metrics')->assertNotFound();
        $this->withServerVariables(['DEVOPS_METRICS_INTERNAL' => '1'])->get('/metrics')->assertOk()->assertHeader('Content-Type', RenderTextFormat::MIME_TYPE.'; charset=utf-8');
        $this->head('/metrics')->assertNotFound();
        config(['observability.enabled' => false]);
        $this->get('/metrics')->assertNotFound();
    }

    public function test_storage_failure_preserves_business_response_and_scrape_returns_503(): void
    {
        config(['observability.database' => $this->directory.'/missing/metrics.sqlite']);
        $this->app->forgetScopedInstances();
        $this->get('/api/test/ok/abc')->assertOk();
        $events = $this->events();
        self::assertSame(['http_request_completed', 'metrics_recording_failed'], array_column($events, 'message'));
        self::assertSame($events[0]['context']['request_id'], $events[1]['context']['request_id']);
        $this->withServerVariables(['DEVOPS_METRICS_INTERNAL' => '1'])->get('/metrics')->assertStatus(503);
        self::assertSame(1, ApiInteraction::count());
    }

    public function test_disabled_metrics_still_emit_http_events_without_storage(): void
    {
        config(['observability.enabled' => false]);
        $before = app(MetricsStore::class)->collect();
        $this->get('/api/test/ok/abc')->assertOk();
        self::assertSame($before, app(MetricsStore::class)->collect());
        self::assertCount(1, $this->events());
    }

    public function test_duplicate_event_is_not_counted_twice(): void
    {
        $request = Request::create('/api/test/ok/abc');
        $request->attributes->set(AuditContext::class, new AuditContext((string) Str::uuid(), []));
        $request->attributes->set('observability.started_at', hrtime(true));
        $event = new RequestHandled($request, response('ok'));
        app(RecordHttpRequest::class)->handle($event);
        app(RecordHttpRequest::class)->handle($event);
        self::assertCount(1, $this->events());
    }

    public function test_logger_failure_does_not_emit_original_exception_or_change_response(): void
    {
        config(['logging.channels.http.handler_with.stream' => '/proc/no-write-secret/events']);
        $this->get('/api/test/failure')->assertStatus(500)->assertDontSee('exception-secret');
        self::assertStringContainsString('route="test.failure",status="500"} 1', app(MetricsStore::class)->collect());
    }

    private function events(): array
    {
        if (! is_file($this->directory.'/events.jsonl')) {
            return [];
        }

        return array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($this->directory.'/events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
