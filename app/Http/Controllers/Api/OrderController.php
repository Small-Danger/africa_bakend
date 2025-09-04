<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\CartSession;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{


    /**
     * Créer une commande à partir du panier (validation via WhatsApp)
     * 
     * @param Request $request - Données de la commande
     * @return JsonResponse - Commande créée avec résumé
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'session_id' => 'required|string',
                'notes' => 'nullable|string|max:1000',
                'whatsapp_phone' => 'nullable|string'
            ], [
                'session_id.required' => 'L\'ID de session du panier est requis',
                'notes.max' => 'Les notes ne peuvent pas dépasser 1000 caractères'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer la session du panier
            $cartSession = CartSession::where('session_id', $request->session_id)
                ->where('expires_at', '>', now())
                ->with(['items.product', 'items.variant'])
                ->first();

            if (!$cartSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session de panier invalide ou expirée'
                ], 404);
            }

            // Vérifier que le panier n'est pas vide
            if ($cartSession->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le panier est vide'
                ], 422);
            }

            // Vérifier la disponibilité des produits
            $unavailableItems = [];
            foreach ($cartSession->items as $item) {
                if ($item->variant) {
                    if (!$item->variant->isAvailable()) {
                        $unavailableItems[] = $item->product->name . ' - ' . $item->variant->name;
                    }
                } elseif (!$item->product->is_active) {
                    $unavailableItems[] = $item->product->name;
                }
            }

            if (!empty($unavailableItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certains produits ne sont plus disponibles',
                    'error' => 'Produits indisponibles : ' . implode(', ', $unavailableItems)
                ], 422);
            }

            // Démarrer une transaction
            DB::beginTransaction();

            try {
                // Calculer le total de la commande
                $totalAmount = $cartSession->items->sum(function ($item) {
                    $price = $item->variant ? $item->variant->price : ($item->product->base_price ?? 0);
                    return $price * $item->quantity;
            });

            // Déterminer le client pour la commande
            $clientId = null;
            $user = $request->user();
            
            \Log::info('🔍 Détermination du client pour la commande', [
                'session_id' => $request->session_id,
                'cart_session_client_id' => $cartSession->client_id,
                'authenticated_user_id' => $user ? $user->id : null,
                'authenticated_user_email' => $user ? $user->email : null,
                'request_headers' => $request->headers->all(),
                'auth_header' => $request->header('Authorization')
            ]);
            
            if ($user) {
                // PRIORITÉ 1: Utiliser l'utilisateur connecté
                $clientId = $user->id;
                \Log::info('✅ Utilisation de l\'utilisateur connecté', ['client_id' => $clientId]);
                
                // Mettre à jour la session du panier avec l'utilisateur connecté
                if (!$cartSession->client_id || $cartSession->client_id !== $user->id) {
                    $cartSession->update(['client_id' => $user->id]);
                    \Log::info('🔄 Session panier mise à jour avec l\'utilisateur connecté');
                }
            } elseif ($cartSession->client_id) {
                // PRIORITÉ 2: Utiliser le client existant de la session
                $clientId = $cartSession->client_id;
                \Log::info('✅ Utilisation du client de la session', ['client_id' => $clientId]);
            } else {
                // PRIORITÉ 3: Créer un utilisateur temporaire seulement si nécessaire
                \Log::info('⚠️ Création d\'un utilisateur temporaire');
                $tempUser = User::create([
                    'name' => 'Client ' . substr($request->session_id, -6),
                    'email' => 'temp_' . time() . '@bs-shop.com',
                    'whatsapp_phone' => '+33000000000', // Téléphone temporaire
                    'role' => 'client',
                    'password' => bcrypt(Str::random(16)),
                    'is_active' => true
                ]);
                $clientId = $tempUser->id;
                
                // Mettre à jour la session avec le nouvel utilisateur
                $cartSession->update(['client_id' => $clientId]);
                \Log::info('🆕 Nouvel utilisateur temporaire créé', ['client_id' => $clientId]);
            }

            // Créer la commande
            \Log::info('📦 Création de la commande', [
                'client_id' => $clientId,
                'total_amount' => $totalAmount,
                'authenticated_user_id' => $user ? $user->id : null
            ]);
            
            $order = Order::create([
                'client_id' => $clientId,
                'total_amount' => $totalAmount,
                'status' => 'en_attente',
                'notes' => $request->notes,
                'whatsapp_message_id' => null // Sera rempli après envoi WhatsApp
            ]);
            
            \Log::info('✅ Commande créée avec succès', [
                'order_id' => $order->id,
                'client_id' => $order->client_id,
                'total_amount' => $order->total_amount
            ]);

            // Créer les éléments de commande
                foreach ($cartSession->items as $cartItem) {
                    $unitPrice = $cartItem->variant ? $cartItem->variant->price : ($cartItem->product->base_price ?? 0);
                    $totalPrice = $unitPrice * $cartItem->quantity;

                OrderItem::create([
                    'order_id' => $order->id,
                        'product_id' => $cartItem->product_id,
                        'product_variant_id' => $cartItem->product_variant_id,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice
                    ]);

                    // Mettre à jour le stock si c'est une variante
                    if ($cartItem->variant && $cartItem->variant->stock_quantity !== null) {
                        $cartItem->variant->decrement('stock_quantity', $cartItem->quantity);
                    }
                }

                // Vider le panier
                $cartSession->items()->delete();

                // Valider la transaction
                DB::commit();

                // Charger les relations pour la réponse
                $order->load(['items.product', 'items.variant', 'client']);

                // Formater la réponse
                $formattedOrder = [
                    'id' => $order->id,
                    'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'notes' => $order->notes,
                    'client_info' => [
                        'id' => $order->client_id,
                        'name' => $order->client->name,
                        'email' => $order->client->email,
                        'is_existing_user' => $user ? true : false
                    ],
                    'items' => $order->items->map(function ($item) {
                        return [
                            'product_name' => $item->product->name,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price
                        ];
                    }),
                    'summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count(),
                        'created_at' => $order->created_at
                    ]
                ];

                return response()->json([
                    'success' => true,
                    'message' => 'Commande créée avec succès ! Préparez-vous pour la validation WhatsApp.',
                    'data' => [
                        'order' => $formattedOrder,
                        'whatsapp_message' => $this->generateWhatsAppMessage($order),
                        'next_steps' => [
                            '1' => 'Vérifiez le résumé de votre commande ci-dessus',
                            '2' => 'Envoyez le message WhatsApp pour confirmer',
                            '3' => 'Attendez la confirmation de l\'administrateur'
                        ]
                    ]
                ], 201);

            } catch (\Exception $e) {
                // Annuler la transaction en cas d'erreur
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la commande',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Liste des commandes du client connecté
     * 
     * @param Request $request - Requête avec utilisateur connecté
     * @return JsonResponse - Liste des commandes du client
     */
    public function index(Request $request): JsonResponse
    {
        try {
            \Log::info('🔍 OrderController::index - Début de la requête');
            
            // Récupérer l'utilisateur connecté
            $user = $request->user();
            \Log::info('👤 Utilisateur connecté:', ['user_id' => $user ? $user->id : null, 'email' => $user ? $user->email : null]);
            
            if (!$user) {
                \Log::warning('❌ Utilisateur non connecté');
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non connecté'
                ], 401);
            }

            // Récupérer les commandes du client avec tous les détails
            \Log::info('🔍 Recherche des commandes pour client_id:', ['client_id' => $user->id]);
            
            $orders = Order::where('client_id', $user->id)
                ->with([
                    'items.product.category', 
                    'items.variant',
                    'client'
                ])
                ->orderBy('created_at', 'desc')
                ->get();
            
            \Log::info('📦 Commandes trouvées:', ['count' => $orders->count()]);

            // Formater les commandes avec tous les détails
            $formattedOrders = $orders->map(function ($order) {
                \Log::info('📋 Formatage commande ID:', ['order_id' => $order->id, 'items_count' => $order->items->count()]);
                
                return [
                    'id' => $order->id,
                    'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'notes' => $order->notes,
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->name,
                            'product_variant_id' => $item->product_variant_id,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                            'product_category' => $item->product->category ? $item->product->category->name : null
                        ];
                    }),
                    'items_summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count(),
                        'products' => $order->items->map(function ($item) {
                            return [
                                'name' => $item->product->name,
                                'variant' => $item->variant ? $item->variant->name : null,
                                'quantity' => $item->quantity,
                                'total_price' => $item->total_price
                            ];
                        })
                    ],
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at
                ];
            });

            \Log::info('✅ Commandes formatées avec succès', ['count' => $formattedOrders->count()]);
            
            return response()->json([
                'success' => true,
                'message' => 'Commandes récupérées avec succès',
                'data' => [
                    'orders' => $formattedOrders,
                    'total' => $formattedOrders->count(),
                    'summary' => [
                        'total_orders' => $formattedOrders->count(),
                        'total_spent' => $formattedOrders->sum('total_amount'),
                        'status_breakdown' => [
                            'en_attente' => $formattedOrders->where('status', 'en_attente')->count(),
                            'acceptée' => $formattedOrders->where('status', 'acceptée')->count(),
                            'prête' => $formattedOrders->where('status', 'prête')->count(),
                            'en_cours' => $formattedOrders->where('status', 'en_cours')->count(),
                            'disponible' => $formattedOrders->where('status', 'disponible')->count(),
                            'annulée' => $formattedOrders->where('status', 'annulée')->count()
                        ]
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('❌ Erreur lors de la récupération des commandes', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des commandes',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Créer une commande pour un client non authentifié (inscription rapide)
     * 
     * @param Request $request - Données de la commande
     * @return JsonResponse - Commande créée avec résumé
     */
    public function storeGuest(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'session_id' => 'required|string',
                'notes' => 'nullable|string|max:1000',
                'whatsapp_phone' => 'nullable|string'
            ], [
                'session_id.required' => 'L\'ID de session du panier est requis',
                'notes.max' => 'Les notes ne peuvent pas dépasser 1000 caractères'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer la session du panier
            $cartSession = CartSession::where('session_id', $request->session_id)
                ->where('expires_at', '>', now())
                ->with(['items.product', 'items.variant'])
                ->first();

            if (!$cartSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session de panier invalide ou expirée'
                ], 404);
            }

            // Vérifier que le panier n'est pas vide
            if ($cartSession->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le panier est vide'
                ], 422);
            }

            // Vérifier la disponibilité des produits
            $unavailableItems = [];
            foreach ($cartSession->items as $item) {
                if ($item->variant) {
                    if (!$item->variant->isAvailable()) {
                        $unavailableItems[] = $item->product->name . ' - ' . $item->variant->name;
                    }
                } elseif (!$item->product->is_active) {
                    $unavailableItems[] = $item->product->name;
                }
            }

            if (!empty($unavailableItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certains produits ne sont plus disponibles',
                    'error' => 'Produits indisponibles : ' . implode(', ', $unavailableItems)
                ], 422);
            }

            // Démarrer une transaction
            DB::beginTransaction();

            try {
                // Calculer le total de la commande
                $totalAmount = $cartSession->items->sum(function ($item) {
                    $price = $item->variant ? $item->variant->price : ($item->product->base_price ?? 0);
                    return $price * $item->quantity;
                });

                // Créer un utilisateur temporaire pour les clients non authentifiés
                \Log::info('🆕 Création d\'un utilisateur temporaire pour commande guest');
                $tempUser = User::create([
                    'name' => 'Client ' . substr($request->session_id, -6),
                    'email' => 'temp_' . time() . '@bs-shop.com',
                    'whatsapp_phone' => '+33000000000', // Téléphone temporaire
                    'role' => 'client',
                    'password' => bcrypt(Str::random(16)),
                    'is_active' => true
                ]);
                $clientId = $tempUser->id;
                
                // Mettre à jour la session avec le nouvel utilisateur
                $cartSession->update(['client_id' => $clientId]);
                \Log::info('🆕 Nouvel utilisateur temporaire créé', ['client_id' => $clientId]);

                // Créer la commande
                \Log::info('📦 Création de la commande guest', [
                    'client_id' => $clientId,
                    'total_amount' => $totalAmount
                ]);
                
                $order = Order::create([
                    'client_id' => $clientId,
                    'total_amount' => $totalAmount,
                    'status' => 'en_attente',
                    'notes' => $request->notes,
                    'whatsapp_message_id' => null
                ]);
                
                \Log::info('✅ Commande guest créée avec succès', [
                    'order_id' => $order->id,
                    'client_id' => $order->client_id,
                    'total_amount' => $order->total_amount
                ]);

                // Créer les éléments de commande
                foreach ($cartSession->items as $cartItem) {
                    $unitPrice = $cartItem->variant ? $cartItem->variant->price : ($cartItem->product->base_price ?? 0);
                    $totalPrice = $unitPrice * $cartItem->quantity;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $cartItem->product_id,
                        'product_variant_id' => $cartItem->product_variant_id,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice
                    ]);

                    // Mettre à jour le stock si c'est une variante
                    if ($cartItem->variant && $cartItem->variant->stock_quantity !== null) {
                        $cartItem->variant->decrement('stock_quantity', $cartItem->quantity);
                    }
                }

                // Vider le panier
                $cartSession->items()->delete();

                // Valider la transaction
                DB::commit();

                // Charger les relations pour la réponse
                $order->load(['items.product', 'items.variant', 'client']);

                // Formater la réponse
                $formattedOrder = [
                    'id' => $order->id,
                    'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'notes' => $order->notes,
                    'client_info' => [
                        'id' => $order->client_id,
                        'name' => $order->client->name,
                        'email' => $order->client->email,
                        'is_existing_user' => false
                    ],
                    'items' => $order->items->map(function ($item) {
                        return [
                            'product_name' => $item->product->name,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price
                        ];
                    }),
                    'summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count(),
                        'created_at' => $order->created_at
                    ]
                ];

                return response()->json([
                    'success' => true,
                    'message' => 'Commande créée avec succès',
                    'data' => [
                        'order' => $formattedOrder
                    ]
                ], 201);

            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            \Log::error('❌ Erreur lors de la création de la commande guest', [
                'error' => $e->getMessage(),
                'session_id' => $request->session_id
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la commande',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Voir une commande spécifique du client connecté
     * 
     * @param Request $request - Requête avec utilisateur connecté
     * @param int $id - ID de la commande
     * @return JsonResponse - Détails de la commande
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer l'utilisateur connecté
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non connecté'
                ], 401);
            }

            // Récupérer la commande avec ses relations
            $order = Order::where('id', $id)
                ->where('client_id', $user->id)
                ->with(['items.product.category', 'items.variant'])
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            // Formater la commande
            $formattedOrder = [
                'id' => $order->id,
                'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->status,
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product' => [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'slug' => $item->product->slug,
                            'image_main' => $item->product->image_main,
                            'category' => [
                                'id' => $item->product->category->id,
                                'name' => $item->product->category->name
                            ]
                        ],
                        'variant' => $item->variant ? [
                            'id' => $item->variant->id,
                            'name' => $item->variant->name,
                            'sku' => $item->variant->sku
                        ] : null,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price
                    ];
                }),
                'summary' => [
                    'total_items' => $order->items->sum('quantity'),
                    'items_count' => $order->items->count(),
                    'has_variants' => $order->items->whereNotNull('variant')->count() > 0
                ],
                'timeline' => [
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at
                ]
            ];

            return response()->json([
                'success' => true,
                'message' => 'Commande récupérée avec succès',
                'data' => $formattedOrder
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la commande',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Liste de toutes les commandes (ADMIN ONLY)
     * 
     * @param Request $request - Requête avec utilisateur admin
     * @return JsonResponse - Liste de toutes les commandes
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Récupérer toutes les commandes avec pagination
            $orders = Order::with(['client', 'items.product', 'items.variant'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            // Formater les commandes
            $formattedOrders = $orders->getCollection()->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'client' => [
                        'id' => $order->client->id,
                        'name' => $order->client->name,
                        'whatsapp_phone' => $order->client->whatsapp_phone
                    ],
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->product->name,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'price' => $item->unit_price,
                            'total_price' => $item->total_price
                        ];
                    }),
                    'items_summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count()
                    ],
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at
                ];
            });

        return response()->json([
            'success' => true,
                'message' => 'Commandes récupérées avec succès',
                'data' => [
                    'orders' => $formattedOrders,
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'last_page' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total()
                    ],
                    'summary' => [
                        'total_orders' => $orders->total(),
                        'total_revenue' => Order::sum('total_amount'),
                        'status_breakdown' => [
                            'en_attente' => Order::where('status', 'en_attente')->count(),
                            'acceptée' => Order::where('status', 'acceptée')->count(),
                            'prête' => Order::where('status', 'prête')->count(),
                            'en_cours' => Order::where('status', 'en_cours')->count(),
                            'disponible' => Order::where('status', 'disponible')->count(),
                            'annulée' => Order::where('status', 'annulée')->count()
                        ]
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des commandes',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Voir les détails d'une commande (ADMIN ONLY)
     * 
     * @param Request $request - Requête avec utilisateur admin
     * @param int $id - ID de la commande
     * @return JsonResponse - Détails complets de la commande
     */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Récupérer la commande avec toutes ses relations
            $order = Order::with([
                'client',
                'items.product.category',
                'items.variant'
            ])->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            // Formater la commande
            $formattedOrder = [
                'id' => $order->id,
                'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'client' => [
                    'id' => $order->client->id,
                    'name' => $order->client->name,
                    'email' => $order->client->email,
                    'whatsapp_phone' => $order->client->whatsapp_phone
                ],
                'status' => $order->status,
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                'whatsapp_message_id' => $order->whatsapp_message_id,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product' => [
                            'id' => $item->product->id,
                            'name' => $item->product->name,
                            'slug' => $item->product->slug,
                            'image_main' => $item->product->image_main,
                            'category' => [
                                'id' => $item->product->category->id,
                                'name' => $item->product->category->name
                            ]
                        ],
                        'variant' => $item->variant ? [
                            'id' => $item->variant->id,
                            'name' => $item->variant->name,
                            'sku' => $item->variant->sku,
                            'price' => $item->variant->price
                        ] : null,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price
                    ];
                }),
                'summary' => [
                    'total_items' => $order->items->sum('quantity'),
                    'items_count' => $order->items->count(),
                    'has_variants' => $order->items->whereNotNull('variant')->count() > 0
                ],
                'timeline' => [
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at
                ]
            ];

        return response()->json([
            'success' => true,
                'message' => 'Commande récupérée avec succès',
                'data' => $formattedOrder
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la commande',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Mettre à jour le statut d'une commande (ADMIN ONLY)
     * 
     * @param Request $request - Nouveau statut
     * @param int $id - ID de la commande
     * @return JsonResponse - Commande mise à jour
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:en_attente,acceptée,prête,en_cours,disponible,annulée',
                'notes' => 'nullable|string|max:1000'
            ], [
                'status.required' => 'Le statut est requis',
                'status.in' => 'Statut invalide',
                'notes.max' => 'Les notes ne peuvent pas dépasser 1000 caractères'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer la commande
            $order = Order::with(['client'])->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée'
                ], 404);
            }

            // Mettre à jour le statut
            $oldStatus = $order->status;
            $order->status = $request->status;
            
            if ($request->has('notes')) {
                $order->notes = $request->notes;
            }
            
            $order->save();

            // Formater la réponse
            $formattedOrder = [
                'id' => $order->id,
                'order_number' => 'CMD-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->status,
                'status_changed' => $oldStatus !== $order->status,
                'old_status' => $oldStatus,
                'client' => [
                    'id' => $order->client->id,
                    'name' => $order->client->name,
                    'whatsapp_phone' => $order->client->whatsapp_phone
                ],
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                'updated_at' => $order->updated_at
            ];

        return response()->json([
            'success' => true,
                'message' => 'Statut de la commande mis à jour avec succès',
                'data' => $formattedOrder
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Générer le message WhatsApp pour la commande
     * 
     * @param Order $order - La commande
     * @return string - Message WhatsApp formaté
     */
    private function generateWhatsAppMessage(Order $order): string
    {
        $message = "🛒 *NOUVELLE COMMANDE BS SHOP*\n\n";
        $message .= "📋 *Commande #" . str_pad($order->id, 6, '0', STR_PAD_LEFT) . "*\n";
        $message .= "💰 *Total: " . number_format($order->total_amount, 2) . " €*\n\n";
        
        $message .= "📦 *PRODUITS COMMANDÉS:*\n";
        foreach ($order->items as $item) {
            $productName = $item->product->name;
            $variantName = $item->variant ? " - " . $item->variant->name : "";
            $quantity = $item->quantity;
            $price = number_format($item->total_price, 2);
            
            $message .= "• {$productName}{$variantName} x{$quantity} = {$price}€\n";
        }
        
        $message .= "\n📝 *NOTES:* " . ($order->notes ?: "Aucune");
        $message .= "\n\n✅ *Confirmez cette commande en répondant 'OUI'*";
        
        return $message;
    }
}
