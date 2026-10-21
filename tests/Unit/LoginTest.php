<?php

namespace Tests\Unit;

use App\Application\Auth\Login;
use App\Domain\Auth\Authenticator;
use App\Domain\Auth\InvalidCredentials;
use App\Domain\Auth\LoginResult;
use PHPUnit\Framework\TestCase;

final class LoginTest extends TestCase
{
    public function test_exact_credentials_and_result_are_delegated(): void
    {
        $result = new LoginResult(42, 'token');
        $port = $this->createMock(Authenticator::class);
        $port->expects(self::once())->method('authenticate')->with('a@example.test', '  ñ 密  ')->willReturn($result);
        self::assertSame($result, (new Login($port))->execute('a@example.test', '  ñ 密  '));
    }

    public function test_errors_are_propagated_unchanged(): void
    {
        foreach ([new InvalidCredentials, new \RuntimeException('infrastructure')] as $error) {
            $port = $this->createMock(Authenticator::class);
            $port->expects(self::once())->method('authenticate')->with('a@example.test', 'password')->willThrowException($error);
            try {
                (new Login($port))->execute('a@example.test', 'password');
                self::fail('Expected error');
            } catch (\Throwable $actual) {
                self::assertSame($error, $actual);
            }
        }
    }
}
