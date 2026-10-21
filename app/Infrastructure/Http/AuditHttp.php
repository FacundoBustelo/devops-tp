<?php

namespace App\Infrastructure\Http;

use App\Application\Audit\RecordInteraction;
use App\Infrastructure\Audit\AuditContext;
use App\Infrastructure\Audit\AuditFallback;
use App\Infrastructure\Audit\HttpInteractionCapture;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

final readonly class AuditHttp
{
    public function __construct(private HttpInteractionCapture $capture, private RecordInteraction $recorder, private AuditFallback $fallback) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! HttpInteractionCapture::includes($request)) {
            return $next($request);
        }
        $context = new AuditContext((string) Str::uuid(), $this->capture->input($request));
        $request->attributes->set(AuditContext::class, $context);
        $response = $next($request); // Laravel's routing pipeline renders exceptions here.
        $response->headers->set('X-Request-ID', $context->requestId);
        if (! $context->isCommitted()) {
            $interaction = $this->capture->interaction($request, $response, $context);
            try {
                $this->recorder->strictly($interaction);
            } catch (Throwable) {
                $this->fallback->record($interaction);
            }
        }

        return $response;
    }
}
