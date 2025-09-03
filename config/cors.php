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

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173', 
        'http://localhost:3000', 
        'http://localhost:8080',
        'http://192.168.11.180:5173', // Votre frontend spécifique
        'http://192.168.11.180:3000', // Alternative possible
        'http://192.168.11.180:8080', // Alternative possible
        'http://192.168.11.0/24', // Réseau local 192.168.11.x
        'http://10.0.0.0/8',     // Réseau local 10.x.x.x
        'http://172.16.0.0/12',   // Réseau local 172.16-31.x.x
        // Ajout d'origines plus permissives pour le développement
        'http://192.168.11.*:5173',
        'http://192.168.11.*:3000',
        'http://192.168.11.*:8080',
        // URLs Railway
        'https://africafrontend-production.up.railway.app',
        'https://*.up.railway.app'
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
