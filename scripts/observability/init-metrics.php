<?php

use App\Infrastructure\Observability\MetricsStore;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if (config('observability.enabled')) {
        app(MetricsStore::class)->initialize();
    }
} catch (Throwable) {
    fwrite(STDERR, "{\"event\":\"metrics_initialization_failed\"}\n");
    exit(1);
}
