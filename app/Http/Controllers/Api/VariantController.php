<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class VariantController extends Controller
{
    /**
     * Voir toutes les variantes d'un produit
     * 
     * @param int $product_id - ID du produit
     * @return JsonResponse - Liste des variantes du produit
     */
    public function index(int $product_id): JsonResponse
    {
        try {
            // Récupérer le produit avec ses variantes
            $product = Product::with(['variants' => function ($query) {
                    $query->where('is_active', true)
                          ->orderBy('sort_order');
                }])
                ->find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier si le produit est actif
            if (!$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Formater les variantes
            $formattedVariants = $product->variants->map(function ($variant) {
                return [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'stock_quantity' => $variant->stock_quantity,
                    'is_available' => $variant->isAvailable(),
                    'sort_order' => $variant->sort_order,
                    'created_at' => $variant->created_at,
                    'updated_at' => $variant->updated_at
                ];
            });

            // Formater la réponse
            $response = [
                'success' => true,
                'message' => 'Variantes récupérées avec succès',
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'base_price' => $product->base_price,
                        'has_variants' => $product->hasVariants()
                    ],
                    'variants' => $formattedVariants,
                    'total' => $formattedVariants->count(),
                    'pricing_summary' => [
                        'min_price' => $formattedVariants->count() > 0 ? $formattedVariants->min('price') : $product->base_price,
                        'max_price' => $formattedVariants->count() > 0 ? $formattedVariants->max('price') : $product->base_price,
                        'has_price_range' => $formattedVariants->count() > 0
                    ]
                ]
            ];

            return response()->json($response, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des variantes',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * [ADMIN] Récupérer toutes les variantes d'un produit (y compris inactives)
     */
    public function adminIndex(int $product_id): JsonResponse
    {
        try {
            // Récupérer le produit avec toutes ses variantes (y compris inactives)
            $product = Product::with(['variants' => function ($query) {
                    $query->orderBy('sort_order');
                }])
                ->find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Formater les variantes
            $formattedVariants = $product->variants->map(function ($variant) {
                return [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'stock_quantity' => $variant->stock_quantity,
                    'is_active' => $variant->is_active,
                    'is_available' => $variant->isAvailable(),
                    'sort_order' => $variant->sort_order,
                    'created_at' => $variant->created_at,
                    'updated_at' => $variant->updated_at
                ];
            });

            // Formater la réponse
            $response = [
                'success' => true,
                'message' => 'Variantes récupérées avec succès',
                'data' => [
                    'variants' => $formattedVariants
                ]
            ];

            return response()->json($response, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des variantes',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Voir une variante spécifique
     * 
     * @param int $id - ID de la variante
     * @return JsonResponse - Détails de la variante
     */
    public function show(int $id): JsonResponse
    {
        try {
            // Récupérer la variante avec ses relations
            $variant = ProductVariant::with(['product.category'])
                ->where('is_active', true)
                ->find($id);

            // Vérifier si la variante existe
            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variante non trouvée'
                ], 404);
            }

            // Vérifier si le produit parent est actif
            if (!$variant->product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Formater la réponse
            $formattedVariant = [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock_quantity' => $variant->stock_quantity,
                'is_available' => $variant->isAvailable(),
                'sort_order' => $variant->sort_order,
                'product' => [
                    'id' => $variant->product->id,
                    'name' => $variant->product->name,
                    'slug' => $variant->product->slug,
                    'description' => $variant->product->description,
                    'base_price' => $variant->product->base_price,
                    'image_main' => $variant->product->image_main ? asset('storage/' . $variant->product->image_main) : null,
                    'category' => [
                        'id' => $variant->product->category->id,
                        'name' => $variant->product->category->name,
                        'slug' => $variant->product->category->slug
                    ]
                ],
                'created_at' => $variant->created_at,
                'updated_at' => $variant->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Variante récupérée avec succès',
                'data' => $formattedVariant
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la variante',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Créer une variante pour un produit (ADMIN ONLY)
     * 
     * @param Request $request - Données de la variante
     * @param int $product_id - ID du produit
     * @return JsonResponse - Variante créée
     */
    public function store(Request $request, int $product_id): JsonResponse
    {
        try {
            // Récupérer le produit parent
            $product = Product::find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier si le produit est actif
            if (!$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Validation des données de création
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'sku' => 'nullable|string|max:100|unique:product_variants,sku',
                'price' => 'required|numeric|min:0',
                'stock_quantity' => 'nullable|integer|min:0',
                'sort_order' => 'nullable|integer|min:0'
            ], [
                'name.required' => 'Le nom de la variante est obligatoire',
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'sku.max' => 'Le SKU ne peut pas dépasser 100 caractères',
                'sku.unique' => 'Ce SKU est déjà utilisé',
                'price.required' => 'Le prix de la variante est obligatoire',
                'price.numeric' => 'Le prix doit être un nombre',
                'price.min' => 'Le prix ne peut pas être négatif',
                'stock_quantity.integer' => 'La quantité en stock doit être un nombre entier',
                'stock_quantity.min' => 'La quantité en stock ne peut pas être négative',
                'sort_order.integer' => 'L\'ordre doit être un nombre entier',
                'sort_order.min' => 'L\'ordre ne peut pas être négatif'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Créer la variante
            $variant = ProductVariant::create([
                'product_id' => $product_id,
                'name' => $request->name,
                'sku' => $request->sku,
                'price' => $request->price,
                'stock_quantity' => $request->stock_quantity,
                'sort_order' => $request->sort_order ?? 0,
                'is_active' => true
            ]);

            // Charger les relations pour la réponse
            $variant->load(['product.category']);

            // Formater la réponse
            $formattedVariant = [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock_quantity' => $variant->stock_quantity,
                'is_available' => $variant->isAvailable(),
                'sort_order' => $variant->sort_order,
                'product' => [
                    'id' => $variant->product->id,
                    'name' => $variant->product->name,
                    'slug' => $variant->product->slug,
                    'base_price' => $variant->product->base_price,
                    'category' => [
                        'id' => $variant->product->category->id,
                        'name' => $variant->product->category->name,
                        'slug' => $variant->product->category->slug
                    ]
                ],
                'created_at' => $variant->created_at,
                'updated_at' => $variant->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Variante créée avec succès',
                'data' => $formattedVariant
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la variante',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Modifier une variante existante (ADMIN ONLY)
     * 
     * @param Request $request - Nouvelles données de la variante
     * @param int $id - ID de la variante à modifier
     * @return JsonResponse - Variante mise à jour
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer la variante à modifier
            $variant = ProductVariant::with(['product.category'])->find($id);

            // Vérifier si la variante existe
            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variante non trouvée'
                ], 404);
            }

            // Vérifier si le produit parent est actif
            if (!$variant->product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Validation des données de mise à jour
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'sku' => 'sometimes|string|max:100|unique:product_variants,sku,' . $id,
                'price' => 'sometimes|numeric|min:0',
                'stock_quantity' => 'sometimes|integer|min:0',
                'sort_order' => 'sometimes|integer|min:0',
                'is_active' => 'sometimes|boolean'
            ], [
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'sku.max' => 'Le SKU ne peut pas dépasser 100 caractères',
                'sku.unique' => 'Ce SKU est déjà utilisé',
                'price.numeric' => 'Le prix doit être un nombre',
                'price.min' => 'Le prix ne peut pas être négatif',
                'stock_quantity.integer' => 'La quantité en stock doit être un nombre entier',
                'stock_quantity.min' => 'La quantité en stock ne peut pas être négative',
                'sort_order.integer' => 'L\'ordre doit être un nombre entier',
                'sort_order.min' => 'L\'ordre ne peut pas être négatif'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Mettre à jour les champs fournis
            if ($request->has('name')) {
                $variant->name = $request->name;
            }

            if ($request->has('sku')) {
                // Forcer la conversion en chaîne pour le SKU
                $sku = $request->sku;
                if ($sku !== null && $sku !== '') {
                    $variant->sku = (string) $sku;
                } else {
                    $variant->sku = null;
                }
            }

            if ($request->has('price')) {
                $variant->price = $request->price;
            }

            if ($request->has('stock_quantity')) {
                $variant->stock_quantity = $request->stock_quantity;
            }

            if ($request->has('sort_order')) {
                $variant->sort_order = $request->sort_order;
            }

            if ($request->has('is_active')) {
                $variant->is_active = $request->is_active;
            }

            // Sauvegarder les modifications
            $variant->save();

            // Formater la réponse
            $formattedVariant = [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'stock_quantity' => $variant->stock_quantity,
                'is_available' => $variant->isAvailable(),
                'sort_order' => $variant->sort_order,
                'is_active' => $variant->is_active,
                'product' => [
                    'id' => $variant->product->id,
                    'name' => $variant->product->name,
                    'slug' => $variant->product->slug,
                    'base_price' => $variant->product->base_price,
                    'category' => [
                        'id' => $variant->product->category->id,
                        'name' => $variant->product->category->name,
                        'slug' => $variant->product->category->slug
                    ]
                ],
                'updated_at' => $variant->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Variante mise à jour avec succès',
                'data' => $formattedVariant
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la variante',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Supprimer une variante (ADMIN ONLY)
     * 
     * @param int $id - ID de la variante à supprimer
     * @return JsonResponse - Message de confirmation
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Récupérer la variante à supprimer
            $variant = ProductVariant::with(['product'])->find($id);

            // Vérifier si la variante existe
            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variante non trouvée'
                ], 404);
            }

            // Vérifier s'il y a des commandes associées à cette variante
            $hasOrders = $variant->orderItems()->exists();
            if ($hasOrders) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer cette variante',
                    'error' => 'Cette variante a des commandes associées. Supprimez d\'abord toutes les commandes.'
                ], 422);
            }

            // Vérifier s'il y a des éléments de panier associés à cette variante
            $hasCartItems = $variant->cartItems()->exists();
            if ($hasCartItems) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer cette variante',
                    'error' => 'Cette variante est dans des paniers. Supprimez d\'abord tous les éléments de panier.'
                ], 422);
            }

            // Supprimer la variante
            $variant->delete();

            return response()->json([
                'success' => true,
                'message' => 'Variante supprimée avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la variante',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
