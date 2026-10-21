<?php

namespace App\Infrastructure\Favorites;

use Illuminate\Database\Eloquent\Model;

final class FavoriteGifModel extends Model
{
    protected $table = 'favorite_gifs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
