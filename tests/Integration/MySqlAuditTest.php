<?php

namespace Tests\Integration;

use App\Application\Audit\RecordInteraction;
use App\Domain\Audit\Interaction;
use App\Infrastructure\Audit\ApiInteraction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MySqlAuditTest extends TestCase
{
    use DatabaseMigrations;

    protected bool $requiresMySql = true;

    public function test_mysql_schema_fk_unique_json_and_utc_persistence(): void
    {
        self::assertSame('mysql', DB::getDriverName());
        self::assertTrue(Schema::hasTable('api_interactions'));
        $user = User::factory()->create();
        $record = new Interaction((string) Str::uuid(), $user->id, 'POST', 'integration', ['body' => ['public' => 'á'], 'query' => [], 'route' => []], 201, ['ok' => true], null, new \DateTimeImmutable('2026-10-09T09:00:00-03:00'));
        app(RecordInteraction::class)->strictly($record);
        $row = ApiInteraction::sole();
        self::assertSame($record->requestData, $row->request_data);
        self::assertSame(['ok' => true], $row->response_data);
        self::assertSame('2026-10-09 12:00:00', $row->occurred_at->format('Y-m-d H:i:s'));
        try {
            app(RecordInteraction::class)->strictly($record);
            self::fail('Unique request_id must reject duplicate insert.');
        } catch (QueryException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
        $user->delete();
        self::assertSame($user->id, $row->fresh()->user_id);
        $user->forceDelete();
        self::assertNull($row->fresh()->user_id);
        try {
            app(RecordInteraction::class)->strictly(new Interaction((string) Str::uuid(), $user->id, 'GET', 'integration', [], 200, [], null, new \DateTimeImmutable));
            self::fail('Foreign key must reject nonexistent actor.');
        } catch (QueryException $e) {
            self::assertSame(1452, $e->errorInfo[1]);
        }
        self::assertSame(1, ApiInteraction::count());
    }
}
