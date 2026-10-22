<?php

namespace App\Infrastructure\Observability;

use PDO;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\PDO as PdoAdapter;
use RuntimeException;
use Throwable;

final class MetricsStore
{
    public const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    public function __construct(private string $path) {}

    public function initialize(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Metrics directory unavailable.');
        }
        if (! chmod($directory, 0700)) {
            throw new RuntimeException('Metrics directory permissions unavailable.');
        }
        $pdo = $this->connect(false);
        if ($pdo->query('PRAGMA journal_mode=WAL')->fetchColumn() !== 'wal') {
            throw new RuntimeException('Metrics WAL unavailable.');
        }
        chmod($this->path, 0600);
        $this->transaction($pdo, true, function () use ($pdo): void {
            $registry = new CollectorRegistry(new PdoAdapter($pdo), false);
            $counter = $this->counter($registry);
            // Finite expected statuses. No fictitious histogram observations.
            foreach ([
                ['POST', 'api.v1.login', [200, 401, 422, 429, 500]],
                ['GET', 'api.v1.gifs.search', [200, 401, 422, 500, 502, 503, 504]],
                ['GET', 'api.v1.gifs.show', [200, 401, 404, 422, 500, 502, 503, 504]],
                ['POST', 'api.v1.favorites.store', [201, 401, 403, 404, 409, 422, 500, 502, 503, 504]],
            ] as [$method, $route, $statuses]) {
                foreach ($statuses as $status) {
                    $counter->incBy(0, [$method, $route, (string) $status]);
                }
            }
        });
    }

    public function record(string $method, string $route, int $status, float $seconds): void
    {
        $pdo = $this->connect();
        $this->transaction($pdo, true, function () use ($pdo, $method, $route, $status, $seconds): void {
            $registry = new CollectorRegistry(new PdoAdapter($pdo), false);
            $this->counter($registry)->inc([$method, $route, (string) $status]);
            $this->histogram($registry)->observe($seconds, [$method, $route]);
        });
    }

    public function collect(): string
    {
        $pdo = $this->connect();

        return $this->transaction($pdo, false, function () use ($pdo): string {
            $registry = new CollectorRegistry(new PdoAdapter($pdo), false);
            $samples = $registry->getMetricFamilySamples();
            if ($samples === []) {
                throw new RuntimeException('Metrics not initialized.');
            }

            return (new RenderTextFormat)->render($samples);
        });
    }

    private function connect(bool $mustExist = true): PDO
    {
        if ($mustExist && (! is_file($this->path) || ! is_readable($this->path))) {
            throw new RuntimeException('Metrics file unavailable.');
        }
        $pdo = new PDO('sqlite:'.$this->path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout=50');
        $pdo->exec('PRAGMA synchronous=NORMAL');

        return $pdo;
    }

    private function transaction(PDO $pdo, bool $write, callable $callback): mixed
    {
        // Acquire the write lock before reading any metadata: avoids WAL upgrade races.
        $pdo->exec($write ? 'BEGIN IMMEDIATE' : 'BEGIN');
        try {
            $result = $callback();
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $exception) {
            $pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    private function counter(CollectorRegistry $registry): Counter
    {
        return $registry->getOrRegisterCounter('devops', 'http_requests_total', 'Completed API requests.', ['method', 'route', 'status']);
    }

    private function histogram(CollectorRegistry $registry): Histogram
    {
        return $registry->getOrRegisterHistogram('devops', 'http_request_duration_seconds', 'API time to final response.', ['method', 'route'], self::BUCKETS);
    }
}
