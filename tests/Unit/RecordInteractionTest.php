<?php

namespace Tests\Unit;

use App\Application\Audit\RecordInteraction;
use App\Application\Audit\Redactor;
use App\Domain\Audit\Interaction;
use App\Domain\Audit\InteractionRepository;
use PHPUnit\Framework\TestCase;

final class RecordInteractionTest extends TestCase
{
    public function test_repository_error_is_propagated_for_transactional_rollback(): void
    {
        $error = new \RuntimeException('repository failure');
        $repository = $this->createMock(InteractionRepository::class);
        $repository->expects(self::once())->method('persist')->willThrowException($error);
        $input = new Interaction('server-id', null, 'POST', 'test', [], 201, [], null, new \DateTimeImmutable);
        try {
            (new RecordInteraction($repository, new Redactor))->strictly($input);
            self::fail('Expected repository error.');
        } catch (\Throwable $actual) {
            self::assertSame($error, $actual);
        }
    }

    public function test_strict_operation_redacts_at_the_application_boundary_without_mutating_input(): void
    {
        $repository = new class implements InteractionRepository
        {
            public ?Interaction $stored = null;

            public function persist(Interaction $interaction): void
            {
                $this->stored = $interaction;
            }
        };
        $input = new Interaction('server-id', null, 'POST', 'test', ['body' => ['password' => 'secret'], 'query' => [], 'route' => []], 200, ['access_token' => 'secret', 'id' => 'AbC'], null, new \DateTimeImmutable);
        (new RecordInteraction($repository, new Redactor))->strictly($input);
        self::assertSame('secret', $input->requestData['body']['password']);
        self::assertSame('secret', $input->responseData['access_token']);
        self::assertSame(Redactor::REDACTED, $repository->stored->requestData['body']['password']);
        self::assertSame(Redactor::REDACTED, $repository->stored->responseData['access_token']);
        self::assertSame('AbC', $repository->stored->responseData['id']);
    }
}
