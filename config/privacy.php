<?php

return [
    'opa_query_url' => env('OPA_QUERY_URL', 'http://localhost:8181/v1/query'),
    'metadata' => json_decode(file_get_contents(base_path('integration/privacy-config.json')), true, 512, JSON_THROW_ON_ERROR),
];
