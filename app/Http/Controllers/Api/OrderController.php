<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\CartSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\OrderPaymentService;
use App\Services\PosClientResolver;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * Créer une commande à partir du panier (validation via WhatsApp)
     *
     * @param  Request  $request  - Données de la commande
     * @return JsonResponse - Commande créée avec résumé
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'session_id' => 'required|string',
                'notes' => 'nullable|string|max:1000',
                'whatsapp_phone' => 'nullable|string',
            ], [
                'session_id.required' => 'L\'ID de session du panier est requis',
                'notes.max' => 'Les notes ne peuvent pas dépasser 1000 caractères',
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Récupérer la session du panier
            $cartSession = CartSession::where('session_id', $request->session_id)
                ->where('expires_at', '>', now())
                ->with(['items.product', 'items.variant'])
                ->first();

            if (! $cartSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session de panier invalide ou expirée',
                ], 404);
            }

            // Vérifier que le panier n'est pas vide
            if ($cartSession->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le panier est vide',
                ], 422);
            }

            // Vérifier la disponibilité des produits
            $unavailableItems = [];
            foreach ($cartSession->items as $item) {
                if ($item->variant) {
                    if (! $item->variant->isAvailable()) {
                        $unavailableItems[] = $item->product->name.' - '.$item->variant->name;
                    }
                } elseif (! $item->product->is_active) {
                    $unavailableItems[] = $item->product->name;
                }
            }

            if (! empty($unavailableItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certains produits ne sont plus disponibles',
                    'error' => 'Produits indisponibles : '.implode(', ', $unavailableItems),
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
                    'auth_header' => $request->header('Authorization'),
                ]);

                if ($user) {
                    // PRIORITÉ 1: Utiliser l'utilisateur connecté
                    $clientId = $user->id;
                    \Log::info('✅ Utilisation de l\'utilisateur connecté', ['client_id' => $clientId]);

                    // Mettre à jour la session du panier avec l'utilisateur connecté
                    if (! $cartSession->client_id || $cartSession->client_id !== $user->id) {
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
                        'name' => 'Client '.substr($request->session_id, -6),
                        'email' => 'temp_'.time().'@bs-shop.com',
                        'whatsapp_phone' => '+22663126849', // Téléphone de contact
                        'role' => 'client',
                        'password' => bcrypt(Str::random(16)),
                        'is_active' => true,
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
                    'authenticated_user_id' => $user ? $user->id : null,
                ]);

                $order = Order::create([
                    'client_id' => $clientId,
                    'total_amount' => $totalAmount,
                    'status' => 'en_attente',
                    'notes' => $request->notes,
                    'whatsapp_message_id' => null, // Sera rempli après envoi WhatsApp
                ]);

                \Log::info('✅ Commande créée avec succès', [
                    'order_id' => $order->id,
                    'client_id' => $order->client_id,
                    'total_amount' => $order->total_amount,
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
                        'total_price' => $totalPrice,
                    ]);
                }

                app(StockService::class)->reserveForOrder($order, $request->user(), 'site');

                // Vider le panier
                $cartSession->items()->delete();

                // Valider la transaction
                DB::commit();

                // Charger les relations pour la réponse
                $order->load(['items.product', 'items.variant', 'client', 'payments']);

                // Formater la réponse
                $formattedOrder = [
                    'id' => $order->id,
                    'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'notes' => $order->notes,
                    ...app(OrderPaymentService::class)->presentForClient($order),
                    'client_info' => [
                        'id' => $order->client_id,
                        'name' => $order->client->name,
                        'email' => $order->client->email,
                        'is_existing_user' => $user ? true : false,
                    ],
                    'items' => $order->items->map(function ($item) {
                        return [
                            'product_name' => $item->product->name,
                            'product_image' => $item->product->image_main,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    }),
                    'summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count(),
                        'created_at' => $order->created_at,
                    ],
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
                            '3' => 'Attendez la confirmation de l\'administrateur',
                        ],
                    ],
                ], 201);

            } catch (\InvalidArgumentException $e) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            } catch (\Exception $e) {
                // Annuler la transaction en cas d'erreur
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la commande',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Liste des commandes du client connecté
     *
     * @param  Request  $request  - Requête avec utilisateur connecté
     * @return JsonResponse - Liste des commandes du client
     */
    public function index(Request $request): JsonResponse
    {
        try {
            \Log::info('🔍 OrderController::index - Début de la requête');

            // Récupérer l'utilisateur connecté
            $user = $request->user();
            \Log::info('👤 Utilisateur connecté:', ['user_id' => $user ? $user->id : null, 'email' => $user ? $user->email : null]);

            if (! $user) {
                \Log::warning('❌ Utilisateur non connecté');

                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non connecté',
                ], 401);
            }

            app(StockService::class)->expireOverdueReservations();

            // Récupérer les commandes du client avec tous les détails
            \Log::info('🔍 Recherche des commandes pour client_id:', ['client_id' => $user->id]);

            $orders = Order::where('client_id', $user->id)
                ->with([
                    'items.product.category',
                    'items.variant',
                    'client',
                    'payments',
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            \Log::info('📦 Commandes trouvées:', ['count' => $orders->count()]);

            // Formater les commandes avec tous les détails
            $formattedOrders = $orders->map(function ($order) {
                \Log::info('📋 Formatage commande ID:', ['order_id' => $order->id, 'items_count' => $order->items->count()]);

                return [
                    'id' => $order->id,
                    'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'notes' => $order->notes,
                    ...app(OrderPaymentService::class)->presentForClient($order),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->name,
                            'product_image' => $item->product->image_main,
                            'product_variant_id' => $item->product_variant_id,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                            'product_category' => $item->product->category ? $item->product->category->name : null,
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
                                'total_price' => $item->total_price,
                            ];
                        }),
                    ],
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
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
                            'annulée' => $formattedOrders->where('status', 'annulée')->count(),
                            'expirée' => $formattedOrders->where('status', 'expirée')->count(),
                        ],
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            \Log::error('❌ Erreur lors de la récupération des commandes', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des commandes',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Créer une commande pour un client non authentifié (inscription rapide)
     *
     * @param  Request  $request  - Données de la commande
     * @return JsonResponse - Commande créée avec résumé
     */
    public function storeGuest(Request $request): JsonResponse
    {
        try {
            // Validation des données
            $validator = Validator::make($request->all(), [
                'session_id' => 'required|string',
                'notes' => 'nullable|string|max:1000',
                'whatsapp_phone' => 'nullable|string',
            ], [
                'session_id.required' => 'L\'ID de session du panier est requis',
                'notes.max' => 'Les notes ne peuvent pas dépasser 1000 caractères',
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Récupérer la session du panier
            $cartSession = CartSession::where('session_id', $request->session_id)
                ->where('expires_at', '>', now())
                ->with(['items.product', 'items.variant'])
                ->first();

            if (! $cartSession) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session de panier invalide ou expirée',
                ], 404);
            }

            // Vérifier que le panier n'est pas vide
            if ($cartSession->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le panier est vide',
                ], 422);
            }

            // Vérifier la disponibilité des produits
            $unavailableItems = [];
            foreach ($cartSession->items as $item) {
                if ($item->variant) {
                    if (! $item->variant->isAvailable()) {
                        $unavailableItems[] = $item->product->name.' - '.$item->variant->name;
                    }
                } elseif (! $item->product->is_active) {
                    $unavailableItems[] = $item->product->name;
                }
            }

            if (! empty($unavailableItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certains produits ne sont plus disponibles',
                    'error' => 'Produits indisponibles : '.implode(', ', $unavailableItems),
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
                    'name' => 'Client '.substr($request->session_id, -6),
                    'email' => 'temp_'.time().'@bs-shop.com',
                    'whatsapp_phone' => '+22663126849', // Téléphone de contact
                    'role' => 'client',
                    'password' => bcrypt(Str::random(16)),
                    'is_active' => true,
                ]);
                $clientId = $tempUser->id;

                // Mettre à jour la session avec le nouvel utilisateur
                $cartSession->update(['client_id' => $clientId]);
                \Log::info('🆕 Nouvel utilisateur temporaire créé', ['client_id' => $clientId]);

                // Créer la commande
                \Log::info('📦 Création de la commande guest', [
                    'client_id' => $clientId,
                    'total_amount' => $totalAmount,
                ]);

                $order = Order::create([
                    'client_id' => $clientId,
                    'total_amount' => $totalAmount,
                    'status' => 'en_attente',
                    'notes' => $request->notes,
                    'whatsapp_message_id' => null,
                ]);

                \Log::info('✅ Commande guest créée avec succès', [
                    'order_id' => $order->id,
                    'client_id' => $order->client_id,
                    'total_amount' => $order->total_amount,
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
                        'total_price' => $totalPrice,
                    ]);
                }

                app(StockService::class)->reserveForOrder($order, $request->user(), 'site');

                // Vider le panier
                $cartSession->items()->delete();

                // Valider la transaction
                DB::commit();

                // Charger les relations pour la réponse
                $order->load(['items.product', 'items.variant', 'client', 'payments']);

                // Formater la réponse
                $formattedOrder = [
                    'id' => $order->id,
                    'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'notes' => $order->notes,
                    ...app(OrderPaymentService::class)->presentForClient($order),
                    'client_info' => [
                        'id' => $order->client_id,
                        'name' => $order->client->name,
                        'email' => $order->client->email,
                        'is_existing_user' => false,
                    ],
                    'items' => $order->items->map(function ($item) {
                        return [
                            'product_name' => $item->product->name,
                            'product_image' => $item->product->image_main,
                            'variant_name' => $item->variant ? $item->variant->name : null,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    }),
                    'summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count(),
                        'created_at' => $order->created_at,
                    ],
                ];

                return response()->json([
                    'success' => true,
                    'message' => 'Commande créée avec succès',
                    'data' => [
                        'order' => $formattedOrder,
                    ],
                ], 201);

            } catch (\InvalidArgumentException $e) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            \Log::error('❌ Erreur lors de la création de la commande guest', [
                'error' => $e->getMessage(),
                'session_id' => $request->session_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la commande',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Voir une commande spécifique du client connecté
     *
     * @param  Request  $request  - Requête avec utilisateur connecté
     * @param  int  $id  - ID de la commande
     * @return JsonResponse - Détails de la commande
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer l'utilisateur connecté
            $user = $request->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non connecté',
                ], 401);
            }

            app(StockService::class)->expireOverdueReservations();

            // Récupérer la commande avec ses relations
            $order = Order::where('id', $id)
                ->where('client_id', $user->id)
                ->with(['items.product.category', 'items.variant', 'payments'])
                ->first();

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée',
                ], 404);
            }

            // Formater la commande
            $formattedOrder = [
                'id' => $order->id,
                'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->status,
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                ...app(OrderPaymentService::class)->presentForClient($order),
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
                                'name' => $item->product->category->name,
                            ],
                        ],
                        'variant' => $item->variant ? [
                            'id' => $item->variant->id,
                            'name' => $item->variant->name,
                            'sku' => $item->variant->sku,
                        ] : null,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                    ];
                }),
                'summary' => [
                    'total_items' => $order->items->sum('quantity'),
                    'items_count' => $order->items->count(),
                    'has_variants' => $order->items->whereNotNull('variant')->count() > 0,
                ],
                'timeline' => [
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ],
            ];

            return response()->json([
                'success' => true,
                'message' => 'Commande récupérée avec succès',
                'data' => $formattedOrder,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la commande',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Liste de toutes les commandes (ADMIN ONLY)
     *
     * @param  Request  $request  - Requête avec utilisateur admin
     * @return JsonResponse - Liste de toutes les commandes
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est admin
            if (! $request->user() || ! $request->user()->hasPermissionTo(\App\Authorization\Permissions::ORDERS_VIEW)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé',
                ], 403);
            }

            app(StockService::class)->expireOverdueReservations();

            $perPage = min(max((int) $request->input('per_page', 20), 1), 1000);
            $payments = app(OrderPaymentService::class);

            $query = Order::query()->with([
                'client',
                'items.product',
                'items.variant',
                'reservations',
                'preorders',
                'payments.recordedBy',
                'cancelledByUser',
            ]);

            if ($request->boolean('to_validate')) {
                $this->scopeAwaitingPayment($query);
            } elseif ($request->filled('status')) {
                $query->where('status', (string) $request->input('status'));
            }

            $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

            $formattedOrders = $orders->getCollection()->map(function ($order) use ($payments) {
                return [
                    'id' => $order->id,
                    'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                    'client' => $this->formatOrderClient($order),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'channel' => $order->channel ?? 'en_ligne',
                    'items' => $order->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->product?->name ?? 'Produit indisponible',
                            'product_image' => $item->product?->image_main,
                            'variant_name' => $item->variant?->name,
                            'quantity' => $item->quantity,
                            'price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ];
                    }),
                    'items_summary' => [
                        'total_items' => $order->items->sum('quantity'),
                        'items_count' => $order->items->count(),
                    ],
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                    'reservation' => app(StockService::class)->presentReservation($order),
                    'preorder' => app(StockService::class)->presentPreorder($order),
                    'cancellation' => $order->presentCancellation(true),
                    ...$payments->present($order),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Commandes récupérées avec succès',
                'data' => [
                    'orders' => $formattedOrders,
                    'can_counter_preorder' => $request->user()->hasPermissionTo(Permissions::ORDERS_COUNTER_PREORDER),
                    'can_record_payment' => $request->user()->hasPermissionTo(Permissions::ORDERS_RECORD_PAYMENT),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'last_page' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                    'summary' => [
                        'total_orders' => Order::query()->count(),
                        'to_validate' => $this->scopeAwaitingPayment(Order::query())->count(),
                        'total_revenue' => Order::sum('total_amount'),
                        'status_breakdown' => [
                            'en_attente' => Order::where('status', 'en_attente')->count(),
                            'acceptée' => Order::where('status', 'acceptée')->count(),
                            'prête' => Order::where('status', 'prête')->count(),
                            'en_cours' => Order::where('status', 'en_cours')->count(),
                            'disponible' => Order::where('status', 'disponible')->count(),
                            'annulée' => Order::where('status', 'annulée')->count(),
                            'expirée' => Order::where('status', 'expirée')->count(),
                        ],
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur adminIndex commandes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des commandes',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Voir les détails d'une commande (ADMIN ONLY)
     *
     * @param  Request  $request  - Requête avec utilisateur admin
     * @param  int  $id  - ID de la commande
     * @return JsonResponse - Détails complets de la commande
     */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur est admin
            if (! $request->user() || ! $request->user()->hasPermissionTo(\App\Authorization\Permissions::ORDERS_VIEW)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé',
                ], 403);
            }

            // Récupérer la commande avec toutes ses relations
            app(StockService::class)->expireOverdueReservations();

            $order = Order::with([
                'client',
                'items.product.category',
                'items.variant',
                'reservations',
                'preorders',
                'payments.recordedBy',
                'cancelledByUser',
            ])->find($id);

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée',
                ], 404);
            }

            // Formater la commande
            $formattedOrder = [
                'id' => $order->id,
                'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'client' => $this->formatOrderClient($order, true),
                'status' => $order->status,
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                'whatsapp_message_id' => $order->whatsapp_message_id,
                'channel' => $order->channel ?? 'en_ligne',
                'reservation' => app(StockService::class)->presentReservation($order),
                'preorder' => app(StockService::class)->presentPreorder($order),
                'cancellation' => $order->presentCancellation(true),
                ...app(OrderPaymentService::class)->present($order),
                'items' => $order->items->map(function ($item) {
                    $product = $item->product;
                    $category = $product?->category;

                    return [
                        'id' => $item->id,
                        'product' => $product ? [
                            'id' => $product->id,
                            'name' => $product->name,
                            'slug' => $product->slug,
                            'image_main' => $product->image_main,
                            'category' => $category ? [
                                'id' => $category->id,
                                'name' => $category->name,
                            ] : null,
                        ] : [
                            'id' => $item->product_id,
                            'name' => 'Produit indisponible',
                            'slug' => null,
                            'image_main' => null,
                            'category' => null,
                        ],
                        'variant' => $item->variant ? [
                            'id' => $item->variant->id,
                            'name' => $item->variant->name,
                            'sku' => $item->variant->sku,
                            'price' => $item->variant->price,
                        ] : null,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'total_price' => $item->total_price,
                    ];
                }),
                'summary' => [
                    'total_items' => $order->items->sum('quantity'),
                    'items_count' => $order->items->count(),
                    'has_variants' => $order->items->whereNotNull('variant')->count() > 0,
                ],
                'timeline' => [
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ],
            ];

            return response()->json([
                'success' => true,
                'message' => 'Commande récupérée avec succès',
                'data' => $formattedOrder,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la commande',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    public function storeCounterPreorder(Request $request): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo(Permissions::ORDERS_COUNTER_PREORDER)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n’avez pas le droit d’enregistrer une précommande au comptoir',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'walk_in_name' => 'required|string|max:255',
            'walk_in_phone' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'walk_in_name.required' => 'Indiquez le nom du client',
            'items.required' => 'Ajoutez au moins un article',
            'items.min' => 'Ajoutez au moins un article',
            'items.*.quantity.min' => 'La quantité doit être au moins 1',
            'items.*.variant_id.exists' => 'Une variante est introuvable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merged = [];
        foreach ($validator->validated()['items'] as $line) {
            $variantId = (int) $line['variant_id'];
            $merged[$variantId] = ($merged[$variantId] ?? 0) + (int) $line['quantity'];
        }

        $stock = app(StockService::class);

        try {
            $order = DB::transaction(function () use ($request, $validator, $merged, $stock) {
                $total = 0;
                $prepared = [];

                foreach ($merged as $variantId => $quantity) {
                    $variant = ProductVariant::query()->with('product')->find($variantId);
                    if (! $variant || ! $variant->is_active || ! $variant->product?->is_active) {
                        throw new \InvalidArgumentException('Un article n’est plus disponible');
                    }
                    if (! $stock->canFulfillFromStock($variant, $quantity)) {
                        throw new \InvalidArgumentException(
                            ($variant->product?->name ?? 'Produit').' · '.$variant->name.' est en rupture'
                        );
                    }

                    $unit = (float) $variant->price;
                    $prepared[] = [
                        'variant' => $variant,
                        'quantity' => $quantity,
                        'unit_price' => $unit,
                        'total_price' => $unit * $quantity,
                    ];
                    $total += $unit * $quantity;
                }

                $name = trim((string) $validator->validated()['walk_in_name']);
                $phone = isset($validator->validated()['walk_in_phone'])
                    ? (trim((string) $validator->validated()['walk_in_phone']) ?: null)
                    : null;
                $existing = $phone
                    ? User::query()->where('role', 'client')->where('whatsapp_phone', $phone)->first()
                    : null;
                $clientId = $existing?->id ?: PosClientResolver::createClient($name, $phone)->id;

                $order = Order::query()->create([
                    'client_id' => $clientId,
                    'total_amount' => $total,
                    'status' => 'en_attente',
                    'channel' => 'en_ligne',
                    'walk_in_name' => $name,
                    'walk_in_phone' => $phone,
                    'notes' => $validator->validated()['notes'] ?? null,
                ]);

                foreach ($prepared as $line) {
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $line['variant']->product_id,
                        'product_variant_id' => $line['variant']->id,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_price' => $line['total_price'],
                    ]);
                }

                $stock->reserveForOrder($order->fresh(['items.variant.product']), $request->user(), 'admin');

                return $order->fresh(['client', 'items.product', 'items.variant', 'reservations', 'preorders']);
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Précommande enregistrée, le client est en file d’attente',
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'order_number' => 'CMD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                    'status' => $order->status,
                    'total_amount' => $order->total_amount,
                    'client' => $this->formatOrderClient($order),
                    'reservation' => $stock->presentReservation($order),
                    'preorder' => $stock->presentPreorder($order),
                ],
            ],
        ], 201);
    }

    /**
     * Mettre à jour le statut d'une commande (ADMIN ONLY)
     *
     * @param  Request  $request  - Nouveau statut
     * @param  int  $id  - ID de la commande
     * @return JsonResponse - Commande mise à jour
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            if (! $request->user() || ! $request->user()->hasPermissionTo(Permissions::ORDERS_VIEW)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé',
                ], 403);
            }

            $requestedStatus = (string) $request->input('status');
            if ($requestedStatus === 'annulée' && ! $request->user()->hasPermissionTo(Permissions::ORDERS_CANCEL)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas le droit d\'annuler une commande',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:en_attente,acceptée,prête,en_cours,disponible,annulée',
                'notes' => 'nullable|string|max:1000',
                'cancellation_reason' => 'required_if:status,annulée|nullable|string|min:3|max:1000',
            ], [
                'status.required' => 'Le statut est requis',
                'status.in' => 'Statut invalide',
                'notes.max' => 'Les notes ne peuvent pas dépasser 1000 caractères',
                'cancellation_reason.required_if' => 'Le motif d’annulation est obligatoire',
                'cancellation_reason.min' => 'Le motif d’annulation est obligatoire (3 caractères minimum)',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = Order::with(['client'])->find($id);

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Commande non trouvée',
                ], 404);
            }

            $newStatus = (string) $request->status;
            $cancelling = $newStatus === 'annulée';

            if ($cancelling && ! $request->user()->hasPermissionTo(Permissions::ORDERS_CANCEL)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous n\'avez pas le droit d\'annuler une commande',
                ], 403);
            }

            if (! $cancelling && ! $request->user()->hasPermissionTo(Permissions::ORDERS_UPDATE_STATUS)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé',
                ], 403);
            }

            if ($order->isClosed()) {
                return response()->json([
                    'success' => false,
                    'message' => $order->status === 'expirée'
                        ? 'Cette commande est expirée'
                        : 'Cette commande est déjà annulée',
                ], 422);
            }

            if ($newStatus === 'acceptée' && $order->status === 'en_attente') {
                app(OrderPaymentService::class)->assertCanAccept($order);
            }

            $oldStatus = $order->status;

            if ($cancelling) {
                $order = app(OrderCancellationService::class)->cancel(
                    $order,
                    $request->user(),
                    (string) $request->input('cancellation_reason'),
                    ($order->channel ?? 'en_ligne') === 'boutique' ? 'pos' : 'admin',
                );
            } else {
                DB::transaction(function () use ($request, $order, $newStatus) {
                    $channel = $order->channel === 'boutique' ? 'pos' : 'site';
                    app(StockService::class)->syncOrderHold($order, $newStatus, $request->user(), $channel);

                    $order->status = $newStatus;

                    if ($request->has('notes')) {
                        $order->notes = $request->notes;
                    }

                    $order->save();
                });
            }

            $fresh = $order->fresh(['payments.recordedBy', 'cancelledByUser', 'client']);
            $payments = app(OrderPaymentService::class)->present($fresh);

            if (! $cancelling && $oldStatus !== $fresh->status) {
                $event = match ($fresh->status) {
                    'acceptée' => \App\Services\OrderNotifier::ACCEPTED,
                    'prête' => \App\Services\OrderNotifier::READY,
                    'disponible' => \App\Services\OrderNotifier::AVAILABLE,
                    default => null,
                };
                if ($event) {
                    try {
                        app(\App\Services\OrderNotifier::class)->notify($fresh, $event);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            $order = $fresh;

            // Formater la réponse
            $formattedOrder = [
                'id' => $order->id,
                'order_number' => 'CMD-'.str_pad($order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->status,
                'status_changed' => $oldStatus !== $order->status,
                'old_status' => $oldStatus,
                'client' => $this->formatOrderClient($order),
                'total_amount' => $order->total_amount,
                'notes' => $order->notes,
                'cancellation' => $order->presentCancellation(true),
                'updated_at' => $order->updated_at,
                ...$payments,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Statut de la commande mis à jour avec succès',
                'data' => $formattedOrder,
            ], 200);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
                'error' => 'Une erreur est survenue',
            ], 500);
        }
    }

    /**
     * Commandes site / comptoir encore en attente et non soldées.
     */
    private function scopeAwaitingPayment($query)
    {
        return $query->where('status', 'en_attente')
            ->where(function ($inner) {
                $inner->whereNull('channel')->orWhere('channel', '!=', 'boutique');
            })
            ->whereRaw('(select coalesce(sum(amount), 0) from order_payments where order_payments.order_id = orders.id) < orders.total_amount');
    }

    /**
     * Formater les informations client d'une commande (client en ligne, invité ou caisse).
     */
    private function formatOrderClient(Order $order, bool $includeEmail = false): array
    {
        if ($order->client) {
            $client = [
                'id' => $order->client->id,
                'name' => $order->client->name,
                'whatsapp_phone' => $order->client->whatsapp_phone,
            ];

            if ($includeEmail) {
                $client['email'] = $order->client->email;
            }

            return $client;
        }

        $client = [
            'id' => null,
            'name' => $order->walk_in_name ?? 'Client invité',
            'whatsapp_phone' => $order->walk_in_phone,
        ];

        if ($includeEmail) {
            $client['email'] = null;
        }

        return $client;
    }

    /**
     * Générer le message WhatsApp pour la commande
     *
     * @param  Order  $order  - La commande
     * @return string - Message WhatsApp formaté
     */
    private function generateWhatsAppMessage(Order $order): string
    {
        $message = "🛒 *NOUVELLE COMMANDE AFRIKRAGA*\n\n";
        $message .= '📋 *Commande #'.str_pad($order->id, 6, '0', STR_PAD_LEFT)."*\n";
        $message .= '💰 *Total: '.(int) round((float) $order->total_amount)." FCFA*\n\n";

        $message .= "📦 *PRODUITS COMMANDÉS:*\n";
        foreach ($order->items as $item) {
            $productName = $item->product?->name ?? 'Produit indisponible';
            $variantName = $item->variant ? ' - '.$item->variant->name : '';
            $quantity = $item->quantity;
            $price = (int) round((float) $item->total_price);

            $message .= "• {$productName}{$variantName} x{$quantity} = {$price} FCFA\n";
        }

        $message .= "\n📝 *NOTES:* ".($order->notes ?: 'Aucune');
        $message .= "\n\n✅ *Confirmez cette commande en répondant 'OUI'*";

        return $message;
    }
}
