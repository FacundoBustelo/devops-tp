<?php

use App\Infrastructure\Http\GetGifByIdController;
use App\Infrastructure\Http\LoginController;
use App\Infrastructure\Http\SaveFavoriteGifController;
use App\Infrastructure\Http\SearchGifsController;
use Illuminate\Support\Facades\Route;

Route::post('v1/login', LoginController::class)->middleware('throttle:login')->name('api.v1.login');

Route::get('v1/gifs', SearchGifsController::class)->middleware('auth:api')->name('api.v1.gifs.search');

Route::get('v1/gifs/{id}', GetGifByIdController::class)->middleware('auth:api')->name('api.v1.gifs.show');

Route::post('v1/favorites', SaveFavoriteGifController::class)->middleware('auth:api')->name('api.v1.favorites.store');
