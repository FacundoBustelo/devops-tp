<?php

namespace App\Infrastructure\Favorites;

use App\Application\Audit\RecordInteraction;
use App\Application\Favorites\PreparedFavorite;
use App\Application\Favorites\SaveFavoriteGif;
use App\Infrastructure\Audit\AuditContext;
use App\Infrastructure\Audit\HttpInteractionCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class SaveFavoriteTransaction
{
    public function __construct(private SaveFavoriteGif $save, private RecordInteraction $recorder, private HttpInteractionCapture $capture) {}

    public function execute(PreparedFavorite $entry, Request $request): JsonResponse
    {
        $context = $request->attributes->get(AuditContext::class);

        return DB::transaction(function () use ($entry, $request, $context) {
            $favorite = $this->save->persist($entry);
            $utc = new \DateTimeZone('UTC');
            $response = response()->json(['data' => [
                'id' => $favorite->id, 'user_id' => $favorite->userId, 'gif_id' => $favorite->gifId->value, 'alias' => $favorite->alias->value,
                'created_at' => $favorite->createdAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $favorite->updatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            ]], 201);
            $this->recorder->strictly($this->capture->interaction($request, $response, $context));
            DB::afterCommit(fn () => $context->markCommitted());

            return $response;
        });
    }
}
