<?php

namespace App\Infrastructure\Http;

use App\Application\Auth\Login;
use App\Infrastructure\Audit\AuditContext;
use Illuminate\Http\JsonResponse;

final class LoginController
{
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
