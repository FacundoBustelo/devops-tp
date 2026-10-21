<?php

return [
    'default' => 'default',
    'documentations' => [
        'default' => [
            'api' => ['title' => 'Prex API v1'],
            'routes' => ['api' => 'docs'],
            'paths' => [
                'use_absolute_path' => false,
                'docs_json' => 'openapi.json',
                'format_to_use_for_docs' => 'json',
                'annotations' => [
                    base_path('app/Infrastructure/Http'),
                    base_path('app/Infrastructure/OpenApi'),
                ],
            ],
        ],
    ],
    'defaults' => [
        'routes' => [
            'docs' => 'docs/openapi.json',
            'oauth2_callback' => null,
            'middleware' => ['api' => [], 'docs' => [], 'asset' => []],
        ],
        'paths' => [
            'docs' => base_path('docs/openapi'),
            'views' => base_path('resources/views/vendor/l5-swagger'),
            'base' => null,
            'excludes' => [],
        ],
        'scanOptions' => ['open_api_spec_version' => '3.0.0'],
        'generate_always' => false,
        'generate_yaml_copy' => false,
        'securityDefinitions' => ['securitySchemes' => [], 'security' => []],
        'proxy' => false,
        'additional_config_url' => null,
        'operations_sort' => null,
        'validator_url' => null,
        'ui' => [
            'authorization' => ['persist_authorization' => false],
        ],
    ],
];
