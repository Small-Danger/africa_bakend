<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SuggestionController extends Controller
{
    /**
     * Obtenir des produits similaires pour une page produit
     * 
     * @param Request $request - Requête avec ID du produit
     * @param int $productId - ID du produit
     * @return JsonResponse - Produits similaires
     */
    public function getSimilarProducts(Request $request, int $productId): JsonResponse
    {
        try {
            // Récupérer le produit principal
            $product = Product::with(['category'])->find($productId);
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé',
                    'error' => 'Le produit demandé n\'existe pas'
                ], 404);
            }

            // Générer différents types de suggestions
            $suggestions = [
                'similar_products' => $this->getSimilarProductsByCategory($product),
                'popular_in_category' => $this->getPopularProductsInCategory($product->category_id),
                'recent_products' => $this->getRecentProducts(4)
            ];

            return response()->json([
                'success' => true,
                'message' => 'Produits similaires récupérés avec succès',
                'data' => $suggestions
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits similaires',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Obtenir des suggestions d'articles pour le panier
     * 
     * @param Request $request - Requête avec session du panier
     * @return JsonResponse - Suggestions d'articles
     */
    public function getCartSuggestions(Request $request): JsonResponse
    {
        try {
            // Récupérer la session du panier pour analyser les articles actuels
            $cartSessionId = $request->header('X-Session-ID');
            $cartItems = [];
            
            if ($cartSessionId) {
                $cartItems = DB::table('cart_items')
                    ->join('cart_sessions', 'cart_items.cart_session_id', '=', 'cart_sessions.id')
                    ->join('products', 'cart_items.product_id', '=', 'products.id')
                    ->where('cart_sessions.session_id', $cartSessionId)
                    ->where('cart_sessions.expires_at', '>', now())
                    ->select('products.id', 'products.category_id', 'products.name')
                    ->get()
                    ->toArray();
            }

            // Générer différents types de suggestions
            $suggestions = [
                'complementary' => $this->getComplementaryProducts($cartItems),
                'frequently_bought_together' => $this->getFrequentlyBoughtTogether($cartItems),
                'similar_products' => $this->getSimilarProductsForCart($cartItems),
                'popular_products' => $this->getPopularProducts(),
                'recent_products' => $this->getRecentProducts()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Suggestions récupérées avec succès',
                'data' => $suggestions
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des suggestions',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Obtenir des produits complémentaires basés sur les catégories du panier
     */
    private function getComplementaryProducts(array $cartItems): array
    {
        if (empty($cartItems)) {
            return $this->getPopularProducts(4);
        }

        // Récupérer les catégories des articles du panier
        $categoryIds = array_unique(array_column($cartItems, 'category_id'));
        $cartProductIds = array_column($cartItems, 'id');

        $products = Product::with(['category', 'variants'])
            ->where('is_active', true)
            ->whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $cartProductIds)
            ->inRandomOrder()
            ->limit(4)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Obtenir des produits fréquemment achetés ensemble
     */
    private function getFrequentlyBoughtTogether(array $cartItems): array
    {
        if (empty($cartItems)) {
            return $this->getPopularProducts(3);
        }

        $cartProductIds = array_column($cartItems, 'id');

        // Analyser les commandes pour trouver des produits fréquemment achetés ensemble
        $frequentlyBought = DB::table('order_items as oi1')
            ->join('order_items as oi2', 'oi1.order_id', '=', 'oi2.order_id')
            ->join('products', 'oi2.product_id', '=', 'products.id')
            ->whereIn('oi1.product_id', $cartProductIds)
            ->whereNotIn('oi2.product_id', $cartProductIds)
            ->where('products.is_active', true)
            ->select('oi2.product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('oi2.product_id')
            ->orderBy('frequency', 'desc')
            ->limit(3)
            ->pluck('product_id')
            ->toArray();

        if (empty($frequentlyBought)) {
            return $this->getSimilarProductsForCart($cartItems, 3);
        }

        $products = Product::with(['category', 'variants'])
            ->whereIn('id', $frequentlyBought)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Obtenir des produits similaires par catégorie pour une page produit
     */
    private function getSimilarProductsByCategory($product, int $limit = 6): array
    {
        $products = Product::with(['category', 'variants'])
            ->where('is_active', true)
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Obtenir les produits populaires dans une catégorie
     */
    private function getPopularProductsInCategory(int $categoryId, int $limit = 4): array
    {
        $products = Product::with(['category', 'variants'])
            ->where('is_active', true)
            ->where('category_id', $categoryId)
            ->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->select('products.*', DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_sold'))
            ->groupBy('products.id')
            ->orderBy('total_sold', 'desc')
            ->orderBy('products.created_at', 'desc')
            ->limit($limit)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Obtenir des produits similaires pour le panier
     */
    private function getSimilarProductsForCart(array $cartItems, int $limit = 4): array
    {
        if (empty($cartItems)) {
            return $this->getPopularProducts($limit);
        }

        $cartProductIds = array_column($cartItems, 'id');
        $categoryIds = array_unique(array_column($cartItems, 'category_id'));

        $products = Product::with(['category', 'variants'])
            ->where('is_active', true)
            ->whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $cartProductIds)
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Obtenir les produits populaires (les plus vendus)
     */
    private function getPopularProducts(int $limit = 4): array
    {
        $products = Product::with(['category', 'variants'])
            ->where('is_active', true)
            ->leftJoin('order_items', 'products.id', '=', 'order_items.product_id')
            ->select('products.*', DB::raw('COALESCE(SUM(order_items.quantity), 0) as total_sold'))
            ->groupBy('products.id')
            ->orderBy('total_sold', 'desc')
            ->orderBy('products.created_at', 'desc')
            ->limit($limit)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Obtenir les produits récents
     */
    private function getRecentProducts(int $limit = 4): array
    {
        $products = Product::with(['category', 'variants'])
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $this->formatProducts($products);
    }

    /**
     * Formater les produits pour la réponse
     */
    private function formatProducts($products): array
    {
        return $products->map(function ($product) {
            $variant = $product->variants->where('is_active', true)->first();
            $price = $variant ? $variant->price : ($product->base_price ?? 0);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => \Str::limit($product->description, 100),
                'image_main' => $product->image_main,
                'price' => $price,
                'base_price' => $product->base_price,
                'category' => [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug
                ],
                'variant' => $variant ? [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price' => $variant->price,
                    'is_available' => $variant->isAvailable()
                ] : null,
                'is_available' => $variant ? $variant->isAvailable() : true,
                'created_at' => $product->created_at
            ];
        })->toArray();
    }
}
