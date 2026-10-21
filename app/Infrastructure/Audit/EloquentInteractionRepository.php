<?php

namespace App\Infrastructure\Audit;

use App\Domain\Audit\Interaction;
use App\Domain\Audit\InteractionRepository;

final class EloquentInteractionRepository implements InteractionRepository
{
    public function persist(Interaction $interaction): void
    {
        ApiInteraction::create([
            'request_id' => $interaction->requestId, 'user_id' => $interaction->userId,
            'method' => $interaction->method, 'service' => $interaction->service,
            'request_data' => $interaction->requestData, 'status' => $interaction->status,
            'response_data' => $interaction->responseData, 'ip' => $interaction->ip,
            'occurred_at' => $interaction->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
