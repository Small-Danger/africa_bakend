<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartSession;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CartController extends Controller
{
    /**
     * Voir les articles du panier (session utilisateur)
     * 
     * @param Request $request - Requête avec X-Session-ID ou utilisateur connecté
     * @return JsonResponse - Contenu du panier avec total et résumé
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Récupérer la session du panier
            $cartSession = $this->getCartSession($request);
            
            if (!$cartSession) {
                return response()->json([
                    'success' => true,
                    'message' => 'Panier vide',
                    'data' => [
                        'items' => [],
                        'summary' => [
                            'total_items' => 0,
                            'total_price' => 0.00,
                            'items_count' => 0
                        ]
                    ]
                ], 200);
            }

            // Charger les éléments du panier avec leurs relations
            $cartItems = $cartSession->items()->with([
                'product.category',
                'variant'
            ])->get();

            // Formater les éléments du panier
            $formattedItems = $cartItems->map(function ($item) {
                $product = $item->product;
                $variant = $item->variant;
                
                return [
                    'id' => $item->id,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'description' => Str::limit($product->description, 100),
                        'image_main' => $product->image_main,
                        'category' => [
                            'id' => $product->category->id,
                            'name' => $product->category->name,
                            'slug' => $product->category->slug
                        ]
                    ],
                    'variant' => $variant ? [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'price' => $variant->price,
                        'is_available' => $variant->isAvailable()
                    ] : null,
                    'quantity' => $item->quantity,
                    'unit_price' => $variant ? $variant->price : ($product->base_price ?? 0),
                    'total_price' => $item->total_price,
                    'added_at' => $item->created_at
                ];
            });

            // Calculer le résumé du panier
            $summary = [
                'total_items' => $cartItems->sum('quantity'),
                'total_price' => $cartItems->sum('total_price'),
                'items_count' => $cartItems->count(),
                'has_variants' => $cartItems->whereNotNull('variant')->count() > 0,
                'session_expires_at' => $cartSession->expires_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Panier récupéré avec succès',
                'data' => [
                    'session_id' => $cartSession->session_id,
                    'items' => $formattedItems,
                    'summary' => $summary
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du panier',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Ajouter un produit (ou une variante) au panier
     * 
     * @param Request $request - Données du produit à ajouter
     * @return JsonResponse - Produit ajouté au panier
     */
    public function add(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'variant_id' => 'nullable|exists:product_variants,id',
                'quantity' => 'required|integer|min:1|max:99'
            ], [
                'product_id.required' => 'L\'ID du produit est requis',
                'product_id.exists' => 'Le produit sélectionné n\'existe pas',
                'variant_id.exists' => 'La variante sélectionnée n\'existe pas',
                'quantity.required' => 'La quantité est requise',
                'quantity.integer' => 'La quantité doit être un nombre entier',
                'quantity.min' => 'La quantité doit être au moins 1',
                'quantity.max' => 'La quantité ne peut pas dépasser 99'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer le produit
            $product = Product::with('category')->find($request->product_id);
            
            // Vérifier si le produit est actif
            if (!$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Vérifier la variante si fournie
            $variant = null;
            if ($request->variant_id) {
                $variant = ProductVariant::find($request->variant_id);
                
                // Vérifier que la variante appartient bien au produit
                if ($variant->product_id !== $product->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cette variante n\'appartient pas au produit sélectionné'
                    ], 422);
                }
                
                // Vérifier si la variante est active
                if (!$variant->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cette variante n\'est pas disponible'
                    ], 403);
                }
                
                // Vérifier la disponibilité du stock
                if (!$variant->isAvailable()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cette variante n\'est plus en stock'
                    ], 422);
                }
            }

            // Récupérer ou créer la session du panier
            $cartSession = $this->getOrCreateCartSession($request);

            // Vérifier si l'article existe déjà dans le panier
            $existingItem = CartItem::where('cart_session_id', $cartSession->id)
                ->where('product_id', $request->product_id)
                ->where('product_variant_id', $request->variant_id)
                ->first();

            if ($existingItem) {
                // Mettre à jour la quantité
                $newQuantity = $existingItem->quantity + $request->quantity;
                
                // Vérifier la limite maximale
                if ($newQuantity > 99) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La quantité totale ne peut pas dépasser 99'
                    ], 422);
                }
                
                $existingItem->quantity = $newQuantity;
                $existingItem->save();
                
                $cartItem = $existingItem;
            } else {
                // Créer un nouvel élément de panier
                $cartItem = CartItem::create([
                    'cart_session_id' => $cartSession->id,
                    'product_id' => $request->product_id,
                    'product_variant_id' => $request->variant_id,
                    'quantity' => $request->quantity
                ]);
            }

            // Charger les relations pour la réponse
            $cartItem->load(['product.category', 'variant']);

            // Formater la réponse
            $formattedItem = [
                'id' => $cartItem->id,
                'product' => [
                    'id' => $cartItem->product->id,
                    'name' => $cartItem->product->name,
                    'slug' => $cartItem->product->slug,
                    'image_main' => $cartItem->product->image_main,
                    'category' => [
                        'id' => $cartItem->product->category->id,
                        'name' => $cartItem->product->category->name
                    ]
                ],
                'variant' => $cartItem->variant ? [
                    'id' => $cartItem->variant->id,
                    'name' => $cartItem->variant->name,
                    'sku' => $cartItem->variant->sku,
                    'price' => $cartItem->variant->price
                ] : null,
                'quantity' => $cartItem->quantity,
                'unit_price' => $cartItem->unit_price,
                'total_price' => $cartItem->total_price,
                'added_at' => $cartItem->created_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Produit ajouté au panier avec succès',
                'data' => [
                    'session_id' => $cartSession->session_id,
                    'cart_item' => $formattedItem,
                    'cart_summary' => [
                        'total_items' => $cartSession->item_count,
                        'total_price' => $cartSession->total,
                        'items_count' => $cartSession->items()->count()
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout au panier',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Modifier la quantité d'un article du panier
     * 
     * @param Request $request - Nouvelle quantité
     * @param int $item_id - ID de l'article du panier
     * @return JsonResponse - Article mis à jour
     */
    public function update(Request $request, int $item_id): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'quantity' => 'required|integer|min:1|max:99'
            ], [
                'quantity.required' => 'La quantité est requise',
                'quantity.integer' => 'La quantité doit être un nombre entier',
                'quantity.min' => 'La quantité doit être au moins 1',
                'quantity.max' => 'La quantité ne peut pas dépasser 99'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer l'article du panier
            $cartItem = CartItem::with(['product.category', 'variant'])
                ->find($item_id);

            // Vérifier si l'article existe
            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article du panier non trouvé'
                ], 404);
            }

            // Vérifier que l'article appartient à la session actuelle
            $cartSession = $this->getCartSession($request);
            if (!$cartSession || $cartItem->cart_session_id !== $cartSession->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à cet article'
                ], 403);
            }

            // Vérifier la disponibilité du stock si c'est une variante
            if ($cartItem->variant && !$cartItem->variant->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cette variante n\'est plus en stock'
                ], 422);
            }

            // Mettre à jour la quantité
            $cartItem->quantity = $request->quantity;
            $cartItem->save();

            // Formater la réponse
            $formattedItem = [
                'id' => $cartItem->id,
                'product' => [
                    'id' => $cartItem->product->id,
                    'name' => $cartItem->product->name,
                    'slug' => $cartItem->product->slug,
                    'image_main' => $cartItem->product->image_main,
                    'category' => [
                        'id' => $cartItem->product->category->id,
                        'name' => $cartItem->product->category->name
                    ]
                ],
                'variant' => $cartItem->variant ? [
                    'id' => $cartItem->variant->id,
                    'name' => $cartItem->variant->name,
                    'sku' => $cartItem->variant->sku,
                    'price' => $cartItem->variant->price
                ] : null,
                'quantity' => $cartItem->quantity,
                'unit_price' => $cartItem->unit_price,
                'total_price' => $cartItem->total_price,
                'updated_at' => $cartItem->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Quantité mise à jour avec succès',
                'data' => [
                    'cart_item' => $formattedItem,
                    'cart_summary' => [
                        'total_items' => $cartSession->item_count,
                        'total_price' => $cartSession->total,
                        'items_count' => $cartSession->items()->count()
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la quantité',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Retirer un article du panier
     * 
     * @param Request $request - Requête avec session
     * @param int $item_id - ID de l'article à retirer
     * @return JsonResponse - Message de confirmation
     */
    public function remove(Request $request, int $item_id): JsonResponse
    {
        try {
            // Récupérer l'article du panier
            $cartItem = CartItem::with(['product'])->find($item_id);

            // Vérifier si l'article existe
            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article du panier non trouvé'
                ], 404);
            }

            // Vérifier que l'article appartient à la session actuelle
            $cartSession = $this->getCartSession($request);
            if (!$cartSession || $cartItem->cart_session_id !== $cartSession->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé à cet article'
                ], 403);
            }

            // Supprimer l'article
            $cartItem->delete();

            // Récupérer le résumé mis à jour du panier
            $updatedSummary = [
                'total_items' => $cartSession->item_count,
                'total_price' => $cartSession->total,
                'items_count' => $cartSession->items()->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Article retiré du panier avec succès',
                'data' => [
                    'removed_item' => [
                        'id' => $cartItem->id,
                        'product_name' => $cartItem->product->name,
                        'quantity' => $cartItem->quantity
                    ],
                    'cart_summary' => $updatedSummary
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'article',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Vider complètement le panier
     * 
     * @param Request $request - Requête avec session
     * @return JsonResponse - Message de confirmation
     */
    public function clear(Request $request): JsonResponse
    {
        try {
            // Récupérer la session du panier
            $cartSession = $this->getCartSession($request);
            
            if (!$cartSession) {
                return response()->json([
                    'success' => true,
                    'message' => 'Le panier est déjà vide'
                ], 200);
            }

            // Supprimer tous les articles du panier
            $cartSession->items()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Panier vidé avec succès',
                'data' => [
                    'cleared_session' => [
                        'session_id' => $cartSession->session_id,
                        'cleared_at' => now()
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du vidage du panier',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Récupérer ou créer une session de panier
     * 
     * @param Request $request - Requête avec X-Session-ID ou utilisateur connecté
     * @return CartSession|null - Session du panier
     */
    private function getOrCreateCartSession(Request $request): CartSession
    {
        // Essayer de récupérer une session existante
        $cartSession = $this->getCartSession($request);
        
        if ($cartSession) {
            return $cartSession;
        }

        // Créer une nouvelle session
        $sessionId = Str::random(32);
        $expiresAt = now()->addDays(30); // Expire dans 30 jours
        
        $cartSession = CartSession::create([
            'session_id' => $sessionId,
            'client_id' => $request->user()?->id, // Utilisateur connecté si disponible
            'expires_at' => $expiresAt
        ]);

        return $cartSession;
    }

    /**
     * Récupérer une session de panier existante
     * 
     * @param Request $request - Requête avec X-Session-ID ou utilisateur connecté
     * @return CartSession|null - Session du panier ou null
     */
    private function getCartSession(Request $request): ?CartSession
    {
        // Essayer de récupérer par session ID dans le header
        if ($request->header('X-Session-ID')) {
            $cartSession = CartSession::where('session_id', $request->header('X-Session-ID'))
                ->where('expires_at', '>', now())
                ->first();
                
            if ($cartSession) {
                return $cartSession;
            }
        }

        // Essayer de récupérer par utilisateur connecté
        if ($request->user()) {
            $cartSession = CartSession::where('client_id', $request->user()->id)
                ->where('expires_at', '>', now())
                ->first();
                
            if ($cartSession) {
                return $cartSession;
            }
        }

        return null;
    }
}
