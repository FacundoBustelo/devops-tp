<?php

return [
    'enabled' => (bool) env('METRICS_ENABLED', false),
    'database' => env('METRICS_DATABASE', '/tmp/devops-observability/metrics.sqlite'),
];
