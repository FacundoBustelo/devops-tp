<?php

return [
    'api_key' => env('GIPHY_API_KEY', ''),
    'connect_timeout' => (float) env('GIPHY_CONNECT_TIMEOUT', 2),
    'timeout' => (float) env('GIPHY_TIMEOUT', 5),
];
