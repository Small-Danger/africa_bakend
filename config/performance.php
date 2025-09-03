<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuration des Performances
    |--------------------------------------------------------------------------
    |
    | Ce fichier contient les configurations pour optimiser les performances
    | de l'application BS Shop.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Durées de cache pour différents types de données
    |
    */
    'cache' => [
        'products' => [
            'index' => 300,      // 5 minutes pour la liste des produits
            'show' => 600,       // 10 minutes pour un produit spécifique
            'categories' => 1800, // 30 minutes pour les catégories
        ],
        'categories' => [
            'index' => 1800,     // 30 minutes pour la liste des catégories
            'show' => 3600,      // 1 heure pour une catégorie spécifique
        ],
        'banners' => 3600,       // 1 heure pour les bannières
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Optimization
    |--------------------------------------------------------------------------
    |
    | Configuration pour optimiser les requêtes de base de données
    |
    */
    'database' => [
        'pagination' => [
            'default_per_page' => 12,
            'max_per_page' => 50,
        ],
        'eager_loading' => [
            'products' => ['category', 'variants', 'images'],
            'categories' => ['children', 'parent'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Optimization
    |--------------------------------------------------------------------------
    |
    | Configuration pour l'optimisation des images
    |
    */
    'images' => [
        'max_size' => 2048,      // 2MB maximum
        'allowed_types' => ['jpeg', 'png', 'jpg', 'gif', 'webp'],
        'compression_quality' => 85,
    ],
];
