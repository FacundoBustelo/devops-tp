<?php

namespace App\Infrastructure\Auth;

use App\Domain\Auth\Authenticator;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Auth\LoginResult;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class PassportAuthenticator implements Authenticator
{
    // Precomputed bcrypt hash: missing users still perform password verification.
    private const DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function authenticate(string $email, string $password): LoginResult
    {
        $user = User::where('email', $email)->first();
        $valid = Hash::check($password, $user?->password ?? self::DUMMY_HASH);
        if (! $user || ! $valid) {
            throw new InvalidCredentials;
        }
        $token = $user->createToken('api-login');

        return new LoginResult((int) $user->id, $token->accessToken);
    }
}
