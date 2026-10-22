<?php

namespace App\Infrastructure\Observability;

use App\Infrastructure\Audit\AuditContext;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Throwable;

final class RecordHttpRequest
{
    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $context = $request->attributes->get(AuditContext::class);
        $start = $request->attributes->get('observability.started_at');
        if (! $context instanceof AuditContext || ! is_int($start) || $request->attributes->get('observability.completed')) {
            return;
        }
        $request->attributes->set('observability.completed', true);
        $seconds = max(0, (hrtime(true) - $start) / 1e9);
        $status = $event->response->getStatusCode();
        $labels = [
            'request_id' => $context->requestId,
            'method' => HttpLabels::method($request),
            'route' => HttpLabels::route($request),
            'status' => $status,
            'duration_ms' => round($seconds * 1000, 3),
        ];
        app(SafeEvents::class)->emit('http_request_completed', $labels, $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'));
        if (config('observability.enabled')) {
            try {
                app(MetricsStore::class)->record($labels['method'], $labels['route'], $status, $seconds);
            } catch (Throwable) {
                app(SafeEvents::class)->emit('metrics_recording_failed', ['request_id' => $context->requestId]);
            }
        }
    }
}
