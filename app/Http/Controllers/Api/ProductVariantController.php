<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductVariantController extends Controller
{
    /**
     * [ADMIN] Récupérer les variantes d'un produit
     */
    public function index(string $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $variants = $product->variants()->orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'message' => 'Variantes récupérées avec succès',
            'data' => [
                'variants' => $variants
            ]
        ]);
    }

    /**
     * [ADMIN] Ajouter une variante à un produit (ex : 500g, 1kg, 50ml)
     */
    public function store(Request $request, string $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'sku' => 'nullable|string|max:100|unique:product_variants,sku',
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'sort_order' => 'integer'
        ]);

        $variant = $product->variants()->create([
            'name' => $request->name,
            'price' => $request->price,
            'sku' => $request->sku,
            'stock_quantity' => $request->stock_quantity,
            'is_active' => $request->is_active ?? true,
            'sort_order' => $request->sort_order ?? 0
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Variante créée avec succès',
            'data' => $variant
        ], 201);
    }

    /**
     * [ADMIN] Modifier une variante
     */
    public function update(Request $request, string $productId, string $variantId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $variant = $product->variants()->findOrFail($variantId);

        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'sku' => 'nullable|string|max:100|unique:product_variants,sku,' . $variantId,
            'stock_quantity' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'sort_order' => 'integer'
        ]);

        $variant->update([
            'name' => $request->name,
            'price' => $request->price,
            'sku' => $request->sku,
            'stock_quantity' => $request->stock_quantity,
            'is_active' => $request->is_active ?? $variant->is_active,
            'sort_order' => $request->sort_order ?? $variant->sort_order
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Variante mise à jour avec succès',
            'data' => $variant
        ]);
    }

    /**
     * [ADMIN] Supprimer une variante
     */
    public function destroy(string $productId, string $variantId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $variant = $product->variants()->findOrFail($variantId);

        // Vérifier s'il y a des commandes associées
        if ($variant->orderItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer une variante ayant des commandes associées'
            ], 400);
        }

        $variant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Variante supprimée avec succès'
        ]);
    }
}
