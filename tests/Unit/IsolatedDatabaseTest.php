<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedDatabase;

final class IsolatedDatabaseTest extends TestCase
{
    public function test_only_allowed_databases_can_pass_the_pre_migration_guard(): void
    {
        IsolatedDatabase::assertAllowed('sqlite', ':memory:');
        IsolatedDatabase::assertAllowed('mysql', 'prex_audit_test');
        IsolatedDatabase::assertAllowed('mysql', 'prex_audit_test', true);
        foreach ([['mysql', 'prex', false], ['mysql', 'prex_audit_test_other', true], ['sqlite', '/tmp/prex.sqlite', false], ['sqlite', ':memory:', true], ['pgsql', 'prex_audit_test', false]] as [$driver, $database, $mysqlOnly]) {
            try {
                IsolatedDatabase::assertAllowed($driver, $database, $mysqlOnly);
                self::fail('Unsafe migration configuration accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Tests require', $e->getMessage());
            }
        }
    }
}
