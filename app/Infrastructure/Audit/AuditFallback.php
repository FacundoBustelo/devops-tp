<?php

namespace App\Infrastructure\Audit;

use App\Domain\Audit\Interaction;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditFallback
{
    public function record(Interaction $interaction): void
    {
        try {
            $this->emit(['event' => 'api_audit_persistence_failed', 'request_id' => $interaction->requestId, 'status' => $interaction->status]);
        } catch (Throwable) {
            try {
                $this->minimal($interaction->requestId);
            } catch (Throwable) { /* Response must survive. */
            }
        }
    }

    protected function emit(array $event): void
    {
        Log::channel('audit')->error('api_audit_persistence_failed', $event);
    }

    protected function minimal(string $requestId): void
    {
        error_log('api_audit_persistence_failed request_id='.$requestId);
    }
}
