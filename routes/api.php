<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\VariantController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SuggestionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\BannerController;

/*
|--------------------------------------------------------------------------
| API Routes - BS Shop Backend
|--------------------------------------------------------------------------
| Routes conformes à l'architecture des contrôleurs
| Gestion des clients et administrateurs
|
*/

// ========================================
// ROUTES D'AUTHENTIFICATION
// ========================================

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'profile']);
    Route::put('/auth/me', [AuthController::class, 'updateProfile']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
});

// ========================================
// ROUTES PUBLIQUES (Côté Client)
// ========================================

// Catégories publiques
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);

// Produits publics
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// Variantes de produits publiques
Route::get('/variants/{variantId}', [VariantController::class, 'show']);

// Images de produits publiques
Route::get('/products/{productId}/images', [ImageController::class, 'index']);

// Bannières publiques
Route::get('/banners', [BannerController::class, 'index']);

// Panier (gestion côté client)
Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'index']);
    Route::post('/', [CartController::class, 'add']);
    Route::put('/{itemId}', [CartController::class, 'update']);
    Route::delete('/{itemId}', [CartController::class, 'remove']);
    Route::delete('/', [CartController::class, 'clear']);
});

// Suggestions d'articles
Route::get('/suggestions/cart', [SuggestionController::class, 'getCartSuggestions']);
Route::get('/suggestions/products/{productId}/similar', [SuggestionController::class, 'getSimilarProducts']);

// Commandes (création côté client - sans authentification pour inscription rapide)
Route::post('/orders/guest', [OrderController::class, 'storeGuest']);

// Commandes (création et consultation côté client - avec authentification)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    
    // Notifications utilisateur
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
});

// ========================================
// ROUTES ADMIN (Protégées)
// ========================================

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    
    // Gestion des catégories
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    
    // Récupérer toutes les catégories (Admin - y compris inactives)
    Route::get('/categories', [CategoryController::class, 'indexAdmin']);
    
    // Upload d'images pour les catégories
    Route::post('/categories/{id}/image', [CategoryController::class, 'uploadImage']);
    
    // Gestion des produits
    Route::get('/products', [ProductController::class, 'adminIndex']); // Liste des produits pour l'admin
    Route::post('/products', [ProductController::class, 'store']);
    Route::post('/products/batch', [ProductController::class, 'storeBatch']); // Création en masse
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    
    // Gestion des variantes de produits
    Route::prefix('products/{productId}/variants')->group(function () {
        Route::get('/', [VariantController::class, 'adminIndex']); // Route admin pour toutes les variantes
        Route::post('/', [VariantController::class, 'store']);
        Route::post('/batch', [VariantController::class, 'storeBatch']); // Création en batch
        Route::put('/{variantId}', [VariantController::class, 'update']);
        Route::delete('/{variantId}', [VariantController::class, 'destroy']);
    });
    
    // Gestion des images de produits
    Route::prefix('products/{productId}/images')->group(function () {
        Route::get('/', [ImageController::class, 'index']);
        Route::post('/', [ImageController::class, 'store']);
        Route::put('/{imageId}', [ImageController::class, 'update']);
        Route::delete('/{imageId}', [ImageController::class, 'destroy']);
        Route::post('/reorder', [ImageController::class, 'updateOrder']);
    });
    
    // Gestion des commandes
    Route::get('/orders', [OrderController::class, 'adminIndex']);
    Route::get('/orders/{id}', [OrderController::class, 'adminShow']);
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    
    // Gestion des notifications
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::post('/notifications/multiple', [NotificationController::class, 'sendMultiple']);
    Route::post('/notifications/promotion', [NotificationController::class, 'sendPromotion']);
    
    // Gestion des clients (anciennes routes - à supprimer progressivement)
    Route::prefix('clients')->group(function () {
        Route::get('/', [AuthController::class, 'listClients']);
        Route::post('/toggle-status', [AuthController::class, 'toggleClientStatus']);
        Route::get('/stats', [AuthController::class, 'getClientStats']);
    });
    
    // Gestion des clients (nouvelles routes avec CustomerController)
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('/stats', [CustomerController::class, 'stats']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::post('/toggle-status', [CustomerController::class, 'toggleStatus']);
        Route::post('/bulk-action', [CustomerController::class, 'bulkAction']);
    });
    
                // Gestion des bannières
            Route::prefix('banners')->middleware('large.upload')->group(function () {
                Route::get('/', [BannerController::class, 'adminIndex']);
                Route::post('/', [BannerController::class, 'store']);
                Route::get('/{id}', [BannerController::class, 'show']);
                Route::put('/{id}', [BannerController::class, 'update']);
                Route::delete('/{id}', [BannerController::class, 'destroy']);
                Route::post('/{id}/toggle-status', [BannerController::class, 'toggleStatus']);
            });
});

// ========================================
// ROUTES DE TEST (à supprimer en production)
// ========================================

if (app()->environment('local')) {
    Route::get('/test', function () {
        return response()->json([
            'message' => 'API BS Shop fonctionne !',
            'timestamp' => now(),
            'version' => '1.0.0',
            'status' => 'ready'
        ]);
    });
    
    Route::get('/test/auth', function () {
        return response()->json([
            'message' => 'Système d\'authentification opérationnel',
            'features' => [
                'register' => 'Inscription client',
                'login' => 'Connexion client/admin',
                'admin_management' => 'Gestion des clients par admin',
                'sanctum' => 'Authentification par tokens'
            ]
        ]);
    });
}
