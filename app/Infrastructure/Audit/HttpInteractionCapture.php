<?php

namespace App\Infrastructure\Audit;

use App\Application\Audit\Redactor;
use App\Domain\Audit\Interaction;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class HttpInteractionCapture
{
    public function __construct(private Redactor $redactor) {}

    public static function includes(Request $request): bool
    {
        return $request->is('api', 'api/*');
    }

    public function input(Request $request): array
    {
        $raw = $request->getContent();
        $body = $raw === '' ? [] : $this->json($raw);

        return $this->redactor->sanitize(['body' => $body, 'query' => $request->query->all()]);
    }

    public function interaction(Request $request, Response $response, AuditContext $context): Interaction
    {
        // Login identity comes only from this request's completed credential verification.
        $user = $request->routeIs('api.v1.login') ? null : $request->user();
        $route = $request->route();

        return new Interaction(
            $context->requestId, $user ? (int) $user->getAuthIdentifier() : $context->actor(),
            $request->method(), $route ? ($route->getName() ?? $route->uri()) : 'api.unmatched',
            [...$context->input, 'route' => $this->redactor->sanitize($route?->originalParameters() ?? [])],
            $response->getStatusCode(),
            $response instanceof StreamedResponse || $response instanceof BinaryFileResponse ? Redactor::OMITTED : $this->json($response->getContent()),
            $request->ip(), new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }

    private function json(string|false $body): mixed
    {
        if ($body === false || $body === '') {
            return Redactor::OMITTED;
        }
        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $this->redactor->sanitize($decoded) : Redactor::OMITTED;
    }
}
