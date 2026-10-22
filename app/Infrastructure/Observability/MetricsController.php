<?php

namespace App\Infrastructure\Observability;

use Illuminate\Http\Request;
use Prometheus\RenderTextFormat;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MetricsController
{
    public function __invoke(Request $request): Response
    {
        abort_unless(config('observability.enabled') && $request->server('DEVOPS_METRICS_INTERNAL') === '1' && $request->isMethod('GET'), 404);
        try {
            return response(app(MetricsStore::class)->collect(), 200, ['Content-Type' => RenderTextFormat::MIME_TYPE, 'Cache-Control' => 'no-store']);
        } catch (Throwable) {
            app(SafeEvents::class)->emit('metrics_collection_failed');

            return response('Metrics unavailable', 503, ['Cache-Control' => 'no-store']);
        }
    }
}
