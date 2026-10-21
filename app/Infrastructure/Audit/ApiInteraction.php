<?php

namespace App\Infrastructure\Audit;

use Illuminate\Database\Eloquent\Model;

final class ApiInteraction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['request_data' => 'json', 'response_data' => 'json', 'occurred_at' => 'immutable_datetime'];
    }
    // Uses the default connection, as the future favorite repository must do.
}
