<?php

namespace App\Infrastructure\Observability;

use App\Infrastructure\Audit\AuditContext;
use Illuminate\Http\Request;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Throwable;

/** Never pass arbitrary exceptions or request data to a logging backend. */
class SafeEvents
{
    public function emit(string $event, array $metadata = [], string $level = 'error'): void
    {
        try {
            // Direct construction avoids LogManager's unsanitized emergency exception report.
            $handler = new StreamHandler(config('logging.channels.http.handler_with.stream', 'php://stderr'));
            $handler->setFormatter(new JsonFormatter);
            $logger = new Logger('http', [$handler], [], new \DateTimeZone('UTC'));
            $logger->log($level, $event, ['event' => $event, ...$metadata]);
        } catch (Throwable) {
            try {
                error_log('{"event":"observability_logging_failed"}');
            } catch (Throwable) {
                // Observability must preserve the response, even if stderr fails.
            }
        }
    }

    public function exception(Throwable $exception, ?Request $request): void
    {
        $this->emit('application_exception', [
            'request_id' => $request?->attributes->get(AuditContext::class)?->requestId,
            'exception_type' => $exception::class,
        ]);
    }
}
