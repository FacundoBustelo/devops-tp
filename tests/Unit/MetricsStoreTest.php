<?php

namespace Tests\Unit;

use App\Infrastructure\Observability\MetricsStore;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MetricsStoreTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/devops-metrics-'.bin2hex(random_bytes(8));
        $this->path = $this->directory.'/metrics.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function test_shared_storage_buckets_and_initial_zero_counters(): void
    {
        $store = new MetricsStore($this->path);
        $store->initialize();
        $initial = $store->collect();
        self::assertStringContainsString('status="503"} 0', $initial);
        self::assertStringNotContainsString('_count{', $initial);
        $store->record('GET', 'api.v1.gifs.search', 503, 0.1);
        (new MetricsStore($this->path))->record('GET', 'api.v1.gifs.search', 200, 0.3);
        $text = (new MetricsStore($this->path))->collect();
        self::assertStringContainsString('status="503"} 1', $text);
        self::assertStringContainsString('devops_http_request_duration_seconds_count{method="GET",route="api.v1.gifs.search"} 2', $text);
        self::assertStringContainsString('route="api.v1.gifs.search",le="0.1"} 1', $text);
        self::assertStringContainsString('route="api.v1.gifs.search",le="+Inf"} 2', $text);
        self::assertStringContainsString('route="api.v1.gifs.search"} 0.4', $text);
        // Reinitialization after a restart increments by zero and preserves existing values.
        $store->initialize();
        self::assertSame($text, $store->collect());
        $pdo = new PDO('sqlite:'.$this->path);
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(0700, fileperms($this->directory) & 0777);
        self::assertSame(0600, fileperms($this->path) & 0777);
    }

    public function test_counter_and_histogram_rollback_together_when_second_write_fails(): void
    {
        $store = new MetricsStore($this->path);
        $store->initialize();
        $before = $store->collect();
        $pdo = new PDO('sqlite:'.$this->path);
        $pdo->exec("CREATE TRIGGER reject_histogram BEFORE INSERT ON prometheus__histograms BEGIN SELECT RAISE(ABORT, 'injected-secret'); END");
        try {
            $store->record('GET', 'api.v1.gifs.search', 200, 0.01);
            self::fail('Injected failure did not occur.');
        } catch (\PDOException) {
            self::assertSame($before, $store->collect());
        }
        $pdo->exec('DROP TRIGGER reject_histogram');
        $store->record('GET', 'api.v1.gifs.search', 200, 0.01);
        self::assertStringContainsString('status="200"} 1', $store->collect());
    }

    public function test_writer_lock_has_a_bounded_busy_wait_and_no_partial_update(): void
    {
        $store = new MetricsStore($this->path);
        $store->initialize();
        $before = $store->collect();
        $lock = new PDO('sqlite:'.$this->path);
        $lock->exec('BEGIN IMMEDIATE');
        $start = hrtime(true);
        try {
            $store->record('GET', 'api.v1.gifs.search', 200, 0.01);
            self::fail('Locked write succeeded.');
        } catch (\PDOException) {
            self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
            self::assertSame($before, $store->collect());
        } finally {
            $lock->exec('ROLLBACK');
        }
    }

    public function test_missing_file_is_not_silently_reinitialized_by_scrapes(): void
    {
        $this->expectException(RuntimeException::class);
        (new MetricsStore($this->path))->collect();
    }
}
