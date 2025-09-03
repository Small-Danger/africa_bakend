<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    /**
     * [ADMIN] Lister tous les clients avec pagination et filtres avancés
     * 
     * @param Request $request - Requête avec pagination et filtres
     * @return JsonResponse - Liste des clients avec statistiques
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Récupérer les paramètres de pagination et filtres
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $status = $request->get('status'); // 'active', 'blocked', 'all'
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            // Construire la requête avec les relations
            $query = User::clients()
                ->withCount(['orders as orders_count'])
                ->with(['orders' => function($q) {
                    $q->latest()->limit(1);
                }]);

            // Appliquer les filtres
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('whatsapp_phone', 'like', "%{$search}%");
                });
            }

            if ($status === 'active') {
                $query->active();
            } elseif ($status === 'blocked') {
                $query->inactive();
            }

            // Appliquer le tri
            $allowedSortFields = ['created_at', 'name', 'email', 'orders_count'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Récupérer les clients avec pagination
            $clients = $query->paginate($perPage);

            // Formater la réponse avec des données enrichies
            $clients->getCollection()->transform(function($client) {
                $lastOrder = $client->orders->first();
                $totalSpent = $client->orders()->sum('total_amount');
                
                // Déterminer le type de client basé sur l'activité
                $customerType = 'new';
                if ($client->orders_count > 10) {
                    $customerType = 'vip';
                } elseif ($client->orders_count > 3) {
                    $customerType = 'regular';
                }

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'whatsapp_phone' => $client->whatsapp_phone,
                    'is_active' => $client->is_active,
                    'status' => $client->is_active ? 'Actif' : 'Bloqué',
                    'created_at' => $client->created_at->format('d/m/Y H:i'),
                    'updated_at' => $client->updated_at->format('d/m/Y H:i'),
                    'orders_count' => $client->orders_count,
                    'last_order' => $lastOrder ? $lastOrder->created_at->format('d/m/Y H:i') : 'Aucune commande',
                    'total_spent' => $totalSpent,
                    'customer_type' => $customerType,
                    'last_login' => $client->updated_at->format('d/m/Y H:i'), // Approximation
                    'avg_order_value' => $client->orders_count > 0 ? round($totalSpent / $client->orders_count, 2) : 0
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Liste des clients récupérée avec succès',
                'data' => [
                    'clients' => $clients->items(),
                    'pagination' => [
                        'current_page' => $clients->currentPage(),
                        'last_page' => $clients->lastPage(),
                        'per_page' => $clients->perPage(),
                        'total' => $clients->total(),
                        'from' => $clients->firstItem(),
                        'to' => $clients->lastItem()
                    ],
                    'filters' => [
                        'search' => $search,
                        'status' => $status,
                        'sort_by' => $sortBy,
                        'sort_order' => $sortOrder
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des clients',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * [ADMIN] Obtenir les statistiques détaillées des clients
     * 
     * @param Request $request - Requête authentifiée
     * @return JsonResponse - Statistiques complètes des clients
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Calculer les statistiques de base
            $totalClients = User::clients()->count();
            $activeClients = User::clients()->active()->count();
            $blockedClients = User::clients()->inactive()->count();
            
            // Nouveaux clients ce mois
            $newClientsThisMonth = User::clients()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            // Nouveaux clients cette semaine
            $newClientsThisWeek = User::clients()
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->count();

            // Statistiques des commandes
            $totalOrders = Order::count();
            $totalRevenue = Order::sum('total_amount') ?? 0;
            $avgOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

            // Clients par type - méthode simplifiée avec des requêtes séparées
            $vipClients = 0;
            $regularClients = 0;
            $newClients = 0;
            $retainedClients = 0;
            
            try {
                // Récupérer tous les clients avec leur nombre de commandes
                $clientsWithOrders = User::clients()
                    ->withCount('orders')
                    ->get();
                
                foreach ($clientsWithOrders as $client) {
                    $ordersCount = $client->orders_count;
                    
                    if ($ordersCount > 10) {
                        $vipClients++;
                    } elseif ($ordersCount > 3) {
                        $regularClients++;
                    } else {
                        $newClients++;
                    }
                    
                    if ($ordersCount >= 2) {
                        $retainedClients++;
                    }
                }
            } catch (\Exception $e) {
                // En cas d'erreur, utiliser des valeurs par défaut
                \Log::warning('Erreur lors du calcul des types de clients: ' . $e->getMessage());
            }
            
            $retentionRate = $totalClients > 0 ? round(($retainedClients / $totalClients) * 100, 2) : 0;

            // Évolution mensuelle
            $monthlyGrowth = $this->calculateMonthlyGrowth();

            return response()->json([
                'success' => true,
                'message' => 'Statistiques des clients récupérées avec succès',
                'data' => [
                    // Statistiques de base
                    'total_clients' => $totalClients,
                    'active_clients' => $activeClients,
                    'blocked_clients' => $blockedClients,
                    'active_percentage' => $totalClients > 0 ? round(($activeClients / $totalClients) * 100, 2) : 0,
                    
                    // Nouveaux clients
                    'new_clients_this_month' => $newClientsThisMonth,
                    'new_clients_this_week' => $newClientsThisWeek,
                    
                    // Statistiques financières
                    'total_orders' => $totalOrders,
                    'total_revenue' => $totalRevenue,
                    'avg_order_value' => $avgOrderValue,
                    
                    // Types de clients
                    'vip_clients' => $vipClients,
                    'regular_clients' => $regularClients,
                    'new_clients' => $newClients,
                    
                    // Rétention
                    'retention_rate' => $retentionRate,
                    'retained_clients' => $retainedClients,
                    
                    // Évolution
                    'monthly_growth' => $monthlyGrowth
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur dans CustomerController::stats: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * [ADMIN] Afficher les détails d'un client spécifique
     * 
     * @param Request $request - Requête authentifiée
     * @param int $id - ID du client
     * @return JsonResponse - Détails du client
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Récupérer le client avec ses commandes
            $client = User::clients()
                ->with(['orders' => function($q) {
                    $q->latest()->limit(10);
                }])
                ->withCount('orders')
                ->find($id);

            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ], 404);
            }

            // Calculer les statistiques du client
            $totalSpent = $client->orders()->sum('total_amount');
            $avgOrderValue = $client->orders_count > 0 ? round($totalSpent / $client->orders_count, 2) : 0;
            
            // Déterminer le type de client
            $customerType = 'new';
            if ($client->orders_count > 10) {
                $customerType = 'vip';
            } elseif ($client->orders_count > 3) {
                $customerType = 'regular';
            }

            return response()->json([
                'success' => true,
                'message' => 'Détails du client récupérés avec succès',
                'data' => [
                    'client' => [
                        'id' => $client->id,
                        'name' => $client->name,
                        'email' => $client->email,
                        'whatsapp_phone' => $client->whatsapp_phone,
                        'is_active' => $client->is_active,
                        'status' => $client->is_active ? 'Actif' : 'Bloqué',
                        'created_at' => $client->created_at->format('d/m/Y H:i'),
                        'updated_at' => $client->updated_at->format('d/m/Y H:i'),
                        'customer_type' => $customerType,
                        'orders_count' => $client->orders_count,
                        'total_spent' => $totalSpent,
                        'avg_order_value' => $avgOrderValue,
                        'last_order' => $client->orders->first()?->created_at?->format('d/m/Y H:i') ?? 'Aucune commande',
                        'recent_orders' => $client->orders->map(function($order) {
                            return [
                                'id' => $order->id,
                                'total_amount' => $order->total_amount,
                                'status' => $order->status,
                                'created_at' => $order->created_at->format('d/m/Y H:i')
                            ];
                        })
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du client',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * [ADMIN] Bloquer/Débloquer un compte client
     * 
     * @param Request $request - Requête avec l'ID du client et l'action
     * @return JsonResponse - Confirmation de l'action
     */
    public function toggleStatus(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'client_id' => 'required|exists:users,id',
                'action' => 'required|in:block,unblock'
            ], [
                'client_id.required' => 'ID du client requis',
                'client_id.exists' => 'Client introuvable',
                'action.required' => 'Action requise',
                'action.in' => 'Action invalide (block ou unblock)'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer le client
            $client = User::clients()->find($request->client_id);
            
            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Client introuvable'
                ], 404);
            }

            // Vérifier que ce n'est pas un admin
            if ($client->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de modifier le statut d\'un administrateur'
                ], 403);
            }

            // Appliquer l'action
            $newStatus = $request->action === 'block' ? false : true;
            $client->is_active = $newStatus;
            $client->save();

            // Supprimer tous les tokens actifs si blocage
            if ($request->action === 'block') {
                $client->tokens()->delete();
            }

            $actionText = $request->action === 'block' ? 'bloqué' : 'débloqué';
            
            return response()->json([
                'success' => true,
                'message' => "Compte client {$actionText} avec succès",
                'data' => [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'is_active' => $client->is_active,
                    'status' => $client->is_active ? 'Actif' : 'Bloqué',
                    'action' => $request->action
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification du statut',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * [ADMIN] Actions en lot sur les clients
     * 
     * @param Request $request - Requête avec les IDs des clients et l'action
     * @return JsonResponse - Résultat des actions en lot
     */
    public function bulkAction(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'client_ids' => 'required|array|min:1',
                'client_ids.*' => 'exists:users,id',
                'action' => 'required|in:block,unblock,delete'
            ], [
                'client_ids.required' => 'IDs des clients requis',
                'client_ids.array' => 'IDs des clients doivent être un tableau',
                'client_ids.min' => 'Au moins un client doit être sélectionné',
                'client_ids.*.exists' => 'Un ou plusieurs clients introuvables',
                'action.required' => 'Action requise',
                'action.in' => 'Action invalide (block, unblock, delete)'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer les clients
            $clients = User::clients()->whereIn('id', $request->client_ids)->get();
            
            if ($clients->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun client trouvé'
                ], 404);
            }

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            DB::beginTransaction();

            try {
                foreach ($clients as $client) {
                    try {
                        switch ($request->action) {
                            case 'block':
                                $client->is_active = false;
                                $client->save();
                                $client->tokens()->delete();
                                $results[] = "Client {$client->name} bloqué";
                                $successCount++;
                                break;
                                
                            case 'unblock':
                                $client->is_active = true;
                                $client->save();
                                $results[] = "Client {$client->name} débloqué";
                                $successCount++;
                                break;
                                
                            case 'delete':
                                // Vérifier qu'il n'y a pas de commandes
                                if ($client->orders()->count() > 0) {
                                    $results[] = "Client {$client->name} non supprimé (a des commandes)";
                                    $errorCount++;
                                } else {
                                    $client->delete();
                                    $results[] = "Client {$client->name} supprimé";
                                    $successCount++;
                                }
                                break;
                        }
                    } catch (\Exception $e) {
                        $results[] = "Erreur pour le client {$client->name}: " . $e->getMessage();
                        $errorCount++;
                    }
                }

                DB::commit();

                $actionText = $request->action === 'block' ? 'bloqués' : 
                             ($request->action === 'unblock' ? 'débloqués' : 'supprimés');

                return response()->json([
                    'success' => true,
                    'message' => "Action en lot terminée: {$successCount} client(s) {$actionText}",
                    'data' => [
                        'action' => $request->action,
                        'total_processed' => count($request->client_ids),
                        'success_count' => $successCount,
                        'error_count' => $errorCount,
                        'results' => $results
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'action en lot',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Calculer la croissance mensuelle des clients
     * 
     * @return array
     */
    private function calculateMonthlyGrowth(): array
    {
        try {
            $currentMonth = User::clients()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            $lastMonth = User::clients()
                ->whereMonth('created_at', now()->subMonth()->month)
                ->whereYear('created_at', now()->subMonth()->year)
                ->count();

            $growth = $lastMonth > 0 ? round((($currentMonth - $lastMonth) / $lastMonth) * 100, 2) : 0;

            return [
                'current_month' => $currentMonth,
                'last_month' => $lastMonth,
                'growth_percentage' => $growth,
                'growth_direction' => $growth > 0 ? 'up' : ($growth < 0 ? 'down' : 'stable')
            ];
        } catch (\Exception $e) {
            \Log::warning('Erreur lors du calcul de la croissance mensuelle: ' . $e->getMessage());
            
            return [
                'current_month' => 0,
                'last_month' => 0,
                'growth_percentage' => 0,
                'growth_direction' => 'stable'
            ];
        }
    }
}
