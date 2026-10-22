<?php

namespace App\Infrastructure\Http;

use App\Application\Auth\Login;
use App\Infrastructure\Audit\AuditContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class LoginController
{
    #[OA\Post(
        path: '/api/v1/login', operationId: 'login', tags: ['Autenticación'],
        summary: 'Obtener un token personal',
        description: 'Login público. Cinco intentos por minuto y por IP, incluidos los intentos con datos inválidos. El token dura 1800 segundos.',
        security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LoginInput')),
        responses: [
            new OA\Response(response: 200, description: 'Token emitido.', headers: [
                new OA\Header(header: 'X-Request-ID', ref: '#/components/headers/RequestId'),
                new OA\Header(header: 'Cache-Control', description: 'Impide almacenar la respuesta con el token.', schema: new OA\Schema(type: 'string', example: 'no-store')),
                new OA\Header(header: 'X-RateLimit-Limit', ref: '#/components/headers/RateLimit'),
                new OA\Header(header: 'X-RateLimit-Remaining', ref: '#/components/headers/RateRemaining'),
            ], content: new OA\JsonContent(ref: '#/components/schemas/LoginResult')),
            new OA\Response(response: 401, ref: '#/components/responses/InvalidCredentials'),
            new OA\Response(response: 422, ref: '#/components/responses/Validation'),
            new OA\Response(response: 429, ref: '#/components/responses/Throttled'),
            new OA\Response(response: 500, ref: '#/components/responses/ServerError'),
        ],
    )]
    public function __invoke(LoginRequest $request, Login $login): JsonResponse
    {
        $data = $request->validated();
        $result = $login->execute($data['email'], $data['password']);
        $request->attributes->get(AuditContext::class)->assignVerifiedActor($result->userId);

        return response()->json([
            'access_token' => $result->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 1800,
            'user' => ['id' => $result->userId],
        ])->header('Cache-Control', 'no-store');
    }
}
