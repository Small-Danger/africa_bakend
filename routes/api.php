<?php

use App\Http\Controllers\Api\AdminCashierController;
use App\Http\Controllers\Api\AdminTeamController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\Pos\PosCashMovementController;
use App\Http\Controllers\Api\Pos\PosCashSessionController;
use App\Http\Controllers\Api\Pos\PosClientController;
use App\Http\Controllers\Api\Pos\PosOrderController;
use App\Http\Controllers\Api\Pos\PosPinController;
use App\Http\Controllers\Api\Pos\PosProductController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SuggestionController;
use App\Http\Controllers\Api\VariantController;
use Illuminate\Support\Facades\Route;

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
Route::post('/auth/google', [AuthController::class, 'googleLogin']);

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

    Route::middleware('permission:products.manage')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
        Route::get('/categories', [CategoryController::class, 'indexAdmin']);
        Route::post('/categories/{id}/image', [CategoryController::class, 'uploadImage']);

        Route::get('/products', [ProductController::class, 'adminIndex']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/products/batch', [ProductController::class, 'storeBatch']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        Route::prefix('products/{productId}/variants')->group(function () {
            Route::get('/', [VariantController::class, 'adminIndex']);
            Route::post('/', [VariantController::class, 'store']);
            Route::post('/batch', [VariantController::class, 'storeBatch']);
            Route::put('/{variantId}', [VariantController::class, 'update']);
            Route::delete('/{variantId}', [VariantController::class, 'destroy']);
        });

        Route::prefix('products/{productId}/images')->group(function () {
            Route::get('/', [ImageController::class, 'index']);
            Route::post('/', [ImageController::class, 'store']);
            Route::put('/{imageId}', [ImageController::class, 'update']);
            Route::delete('/{imageId}', [ImageController::class, 'destroy']);
            Route::post('/reorder', [ImageController::class, 'updateOrder']);
        });
    });

    Route::middleware('permission:orders.view')->group(function () {
        Route::get('/orders', [OrderController::class, 'adminIndex']);
        Route::get('/orders/{id}', [OrderController::class, 'adminShow']);
        Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::post('/notifications', [NotificationController::class, 'store']);
        Route::post('/notifications/multiple', [NotificationController::class, 'sendMultiple']);
        Route::post('/notifications/promotion', [NotificationController::class, 'sendPromotion']);
    });

    Route::middleware('permission:customers.view')->prefix('clients')->group(function () {
        Route::get('/', [AuthController::class, 'listClients']);
        Route::post('/toggle-status', [AuthController::class, 'toggleClientStatus']);
        Route::get('/stats', [AuthController::class, 'getClientStats']);
    });

    Route::middleware('permission:customers.view')->prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('/stats', [CustomerController::class, 'stats']);
        Route::get('/{id}', [CustomerController::class, 'show']);
        Route::post('/toggle-status', [CustomerController::class, 'toggleStatus']);
        Route::post('/bulk-action', [CustomerController::class, 'bulkAction']);
    });

    Route::middleware('permission:team.manage,team.manage_staff')->prefix('cashiers')->group(function () {
        Route::get('/', [AdminCashierController::class, 'index']);
        Route::post('/', [AdminCashierController::class, 'store']);
        Route::put('/{id}', [AdminCashierController::class, 'update']);
        Route::post('/{id}/toggle-status', [AdminCashierController::class, 'toggleStatus']);
    });

    Route::prefix('banners')->middleware(['permission:banners.manage', 'large.upload'])->group(function () {
        Route::get('/', [BannerController::class, 'adminIndex']);
        Route::post('/', [BannerController::class, 'store']);
        Route::get('/{id}', [BannerController::class, 'show']);
        Route::put('/{id}', [BannerController::class, 'update']);
        Route::delete('/{id}', [BannerController::class, 'destroy']);
        Route::post('/{id}/toggle-status', [BannerController::class, 'toggleStatus']);
    });
});

Route::middleware(['auth:sanctum', 'permission:team.manage,team.manage_staff'])
    ->prefix('admin/team')
    ->group(function () {
        Route::get('/', [AdminTeamController::class, 'index']);
        Route::post('/', [AdminTeamController::class, 'store']);
        Route::put('/{id}', [AdminTeamController::class, 'update']);
        Route::post('/{id}/toggle-status', [AdminTeamController::class, 'toggleStatus']);
    });

// ========================================
// ROUTES CAISSE (POS) — admin & caissière
// ========================================

Route::middleware(['auth:sanctum', 'pos'])->prefix('pos')->group(function () {
    Route::prefix('cash-session')->group(function () {
        Route::get('/current', [PosCashSessionController::class, 'current']);
        Route::post('/open', [PosCashSessionController::class, 'open']);
        Route::post('/close', [PosCashSessionController::class, 'close']);
    });

    Route::get('/products/search', [PosProductController::class, 'search']);
    Route::get('/clients/search', [PosClientController::class, 'search']);
    Route::post('/clients/quick-create', [PosClientController::class, 'quickCreate']);

    Route::post('/orders', [PosOrderController::class, 'store']);
    Route::get('/orders/today', [PosOrderController::class, 'today']);
    Route::post('/orders/{id}/cancel', [PosOrderController::class, 'cancel']);

    Route::get('/cash-movements/current-session', [PosCashMovementController::class, 'currentSession']);
    Route::post('/cash-movements', [PosCashMovementController::class, 'store']);

    Route::get('/pin/status', [PosPinController::class, 'hasPin']);
    Route::post('/set-pin', [PosPinController::class, 'setPin']);
    Route::post('/unlock', [PosPinController::class, 'unlock']);
});

// ========================================
// SANTÉ API (monitoring / Railway healthcheck)
// ========================================

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'afrikraga-api',
        'version' => '1.1.0-pos',
        'timestamp' => now()->toIso8601String(),
    ]);
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
            'status' => 'ready',
        ]);
    });

    Route::get('/test/auth', function () {
        return response()->json([
            'message' => 'Système d\'authentification opérationnel',
            'features' => [
                'register' => 'Inscription client',
                'login' => 'Connexion client/admin',
                'admin_management' => 'Gestion des clients par admin',
                'sanctum' => 'Authentification par tokens',
            ],
        ]);
    });
}
