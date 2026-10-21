<?php

namespace App\Domain\Audit;

use DateTimeImmutable;

final readonly class Interaction
{
    public function __construct(
        public string $requestId,
        public ?int $userId,
        public string $method,
        public string $service,
        public array $requestData,
        public int $status,
        public mixed $responseData,
        public ?string $ip,
        public DateTimeImmutable $occurredAt,
    ) {}
}
