<?php

use App\Infrastructure\Observability\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/metrics', MetricsController::class)->name('observability.metrics');
