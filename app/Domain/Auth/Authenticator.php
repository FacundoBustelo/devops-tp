<?php

namespace App\Domain\Auth;

interface Authenticator
{
    public function authenticate(string $email, string $password): LoginResult;
}
