<?php

namespace App\Domain\Auth;

final readonly class LoginResult
{
    public function __construct(public int $userId, public string $accessToken) {}
}
