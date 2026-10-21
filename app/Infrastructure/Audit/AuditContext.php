<?php

namespace App\Infrastructure\Audit;

final class AuditContext
{
    private ?int $actor = null;

    private bool $confirmed = false;

    public function __construct(public readonly string $requestId, public readonly array $input) {}

    // Only call after authentication/credential verification; never with client input.
    public function assignVerifiedActor(int $userId): void
    {
        $this->actor = $userId;
    }

    public function actor(): ?int
    {
        return $this->actor;
    }

    public function markCommitted(): void
    {
        $this->confirmed = true;
    }

    public function isCommitted(): bool
    {
        return $this->confirmed;
    }
}
