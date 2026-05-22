<?php
return [
    'allowed_origins' => ['*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-Token'],
    'expose_headers' => [],
    'max_age' => 86400,
    'allow_credentials' => true,
];
