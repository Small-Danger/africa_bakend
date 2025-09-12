<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'sanctum/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173', 
        'http://localhost:3000', 
        'http://localhost:8080',
        'https://afrikraga.com',
        'https://www.afrikraga.com',
        'https://api.afrikraga.com'
    ],

    'allowed_origins_patterns' => [
        'http://192.168.11.*:5173',
        'http://192.168.11.*:3000',
        'http://192.168.11.*:8080'
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

    'allowed_credentials' => true,

];
