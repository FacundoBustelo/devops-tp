<?php

namespace App\Application\Audit;

use App\Domain\Audit\Interaction;
use App\Domain\Audit\InteractionRepository;

final readonly class RecordInteraction
{
    public function __construct(private InteractionRepository $repository, private Redactor $redactor) {}

    // No catch: the transactional caller must roll back when this operation fails.
    public function strictly(Interaction $interaction): void
    {
        $this->repository->persist(new Interaction(
            $interaction->requestId, $interaction->userId, $interaction->method, $interaction->service,
            $this->redactor->sanitize($interaction->requestData), $interaction->status,
            $this->redactor->sanitize($interaction->responseData), $interaction->ip, $interaction->occurredAt,
        ));
    }
}
