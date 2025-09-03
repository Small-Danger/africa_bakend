<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuration des uploads
    |--------------------------------------------------------------------------
    |
    | Configuration pour les uploads de fichiers, notamment les images
    | de bannières qui peuvent être volumineuses.
    |
    */

    'max_file_size' => 102400, // 100MB en KB
    'allowed_mimes' => [
        'image/jpeg',
        'image/png', 
        'image/jpg',
        'image/gif',
        'image/webp'
    ],
    'max_execution_time' => 300, // 5 minutes
    'memory_limit' => '256M',
];
