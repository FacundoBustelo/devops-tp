<?php

namespace App\Application\Auth;

use App\Domain\Auth\Authenticator;
use App\Domain\Auth\LoginResult;

final readonly class Login
{
    public function __construct(private Authenticator $authenticator) {}

    public function execute(string $email, string $password): LoginResult
    {
        return $this->authenticator->authenticate($email, $password);
    }
}
