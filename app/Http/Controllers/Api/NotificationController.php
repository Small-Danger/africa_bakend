<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Voir ses notifications (client connecté)
     * 
     * @param Request $request - Requête avec utilisateur connecté
     * @return JsonResponse - Liste des notifications du client
     */
    public function index(Request $request): JsonResponse
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

            // Récupérer les notifications de l'utilisateur
            $notifications = Notification::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            // Formater les notifications
            $formattedNotifications = $notifications->getCollection()->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => $notification->type,
                    'is_read' => $notification->is_read,
                    'data' => $notification->data,
                    'created_at' => $notification->created_at,
                    'read_at' => $notification->read_at
                ];
            });

            // Calculer les statistiques
            $stats = [
                'total' => Notification::where('user_id', $user->id)->count(),
                'unread' => Notification::where('user_id', $user->id)->where('is_read', false)->count(),
                'read' => Notification::where('user_id', $user->id)->where('is_read', true)->count()
            ];

        return response()->json([
            'success' => true,
                'message' => 'Notifications récupérées avec succès',
                'data' => [
                    'notifications' => $formattedNotifications,
                    'pagination' => [
                        'current_page' => $notifications->currentPage(),
                        'last_page' => $notifications->lastPage(),
                        'per_page' => $notifications->perPage(),
                        'total' => $notifications->total()
                    ],
                    'statistics' => $stats
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des notifications',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Marquer une notification comme lue
     * 
     * @param Request $request - Requête avec utilisateur connecté
     * @param int $id - ID de la notification
     * @return JsonResponse - Notification mise à jour
     */
    public function markAsRead(Request $request, int $id): JsonResponse
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

            // Récupérer la notification
            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$notification) {
        return response()->json([
                    'success' => false,
                    'message' => 'Notification non trouvée'
                ], 404);
            }

            // Marquer comme lue si ce n'est pas déjà fait
            if (!$notification->is_read) {
                $notification->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);
            }

            // Formater la réponse
            $formattedNotification = [
                'id' => $notification->id,
                'title' => $notification->title,
                'message' => $notification->message,
                'type' => $notification->type,
                'is_read' => $notification->is_read,
                'data' => $notification->data,
                'created_at' => $notification->created_at,
                'read_at' => $notification->read_at
            ];

        return response()->json([
            'success' => true,
                'message' => 'Notification marquée comme lue',
                'data' => $formattedNotification
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la notification',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Marquer toutes les notifications comme lues
     * 
     * @param Request $request - Requête avec utilisateur connecté
     * @return JsonResponse - Message de confirmation
     */
    public function markAllAsRead(Request $request): JsonResponse
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

            // Marquer toutes les notifications non lues comme lues
            $updatedCount = Notification::where('user_id', $user->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);

        return response()->json([
            'success' => true,
                'message' => $updatedCount . ' notification(s) marquée(s) comme lue(s)',
                'data' => [
                    'updated_count' => $updatedCount,
                    'remaining_unread' => 0
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des notifications',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Supprimer une notification
     * 
     * @param Request $request - Requête avec utilisateur connecté
     * @param int $id - ID de la notification
     * @return JsonResponse - Message de confirmation
     */
    public function destroy(Request $request, int $id): JsonResponse
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

            // Récupérer la notification
            $notification = Notification::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification non trouvée'
                ], 404);
            }

            // Supprimer la notification
        $notification->delete();

        return response()->json([
            'success' => true,
                'message' => 'Notification supprimée avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la notification',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Envoyer une notification à un client (ADMIN ONLY)
     * 
     * @param Request $request - Données de la notification
     * @return JsonResponse - Notification envoyée
     */
    public function store(Request $request): JsonResponse
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
                'user_id' => 'required|exists:users,id',
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'type' => 'required|in:info,success,warning,error,order_update,promotion',
                'data' => 'nullable|array'
            ], [
                'user_id.required' => 'L\'ID de l\'utilisateur est requis',
                'user_id.exists' => 'L\'utilisateur sélectionné n\'existe pas',
                'title.required' => 'Le titre est requis',
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères',
                'message.required' => 'Le message est requis',
                'message.max' => 'Le message ne peut pas dépasser 1000 caractères',
                'type.required' => 'Le type est requis',
                'type.in' => 'Type de notification invalide'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier que l'utilisateur cible existe et est actif
            $targetUser = User::find($request->user_id);
            if (!$targetUser || !($targetUser->is_active ?? true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'utilisateur cible n\'est pas disponible'
                ], 422);
            }

            // Créer la notification
            $notification = Notification::create([
                'user_id' => $request->user_id,
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'data' => $request->data ?? [],
                'is_read' => false
            ]);

            // Formater la réponse
            $formattedNotification = [
                'id' => $notification->id,
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'whatsapp_phone' => $targetUser->whatsapp_phone
                ],
                'title' => $notification->title,
                'message' => $notification->message,
                'type' => $notification->type,
                'data' => $notification->data,
                'is_read' => $notification->is_read,
                'created_at' => $notification->created_at
            ];

        return response()->json([
            'success' => true,
                'message' => 'Notification envoyée avec succès',
                'data' => $formattedNotification
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi de la notification',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Envoyer une notification à plusieurs clients (ADMIN ONLY)
     * 
     * @param Request $request - Données de la notification multiple
     * @return JsonResponse - Notifications envoyées
     */
    public function sendMultiple(Request $request): JsonResponse
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
                'user_ids' => 'required|array|min:1',
                'user_ids.*' => 'exists:users,id',
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'type' => 'required|in:info,success,warning,error,order_update,promotion',
                'data' => 'nullable|array'
            ], [
                'user_ids.required' => 'Les IDs des utilisateurs sont requis',
                'user_ids.array' => 'Les IDs doivent être dans un tableau',
                'user_ids.min' => 'Au moins un utilisateur doit être sélectionné',
                'user_ids.*.exists' => 'Un des utilisateurs sélectionnés n\'existe pas',
                'title.required' => 'Le titre est requis',
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères',
                'message.required' => 'Le message est requis',
                'message.max' => 'Le message ne peut pas dépasser 1000 caractères',
                'type.required' => 'Le type est requis',
                'type.in' => 'Type de notification invalide'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier que tous les utilisateurs cibles existent et sont actifs
            $targetUsers = User::whereIn('id', $request->user_ids)
                ->where('is_active', true)
                ->get();

            if ($targetUsers->count() !== count($request->user_ids)) {
        return response()->json([
                    'success' => false,
                    'message' => 'Certains utilisateurs ne sont pas disponibles'
                ], 422);
            }

            $notifications = [];
            $errors = [];

            // Créer les notifications pour chaque utilisateur
            foreach ($targetUsers as $user) {
                try {
                    $notification = Notification::create([
                        'user_id' => $user->id,
                        'title' => $request->title,
                        'message' => $request->message,
                        'type' => $request->type,
                        'data' => $request->data ?? [],
                        'is_read' => false
                    ]);

                    $notifications[] = [
                        'id' => $notification->id,
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'created_at' => $notification->created_at
                    ];

                } catch (\Exception $e) {
                    $errors[] = "Erreur pour l'utilisateur {$user->name}: " . $e->getMessage();
                }
            }

            // Si des erreurs sont survenues, retourner les erreurs
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreurs lors de l\'envoi de certaines notifications',
                    'errors' => $errors,
                    'sent_notifications' => $notifications
                ], 422);
            }

        return response()->json([
            'success' => true,
                'message' => count($notifications) . ' notification(s) envoyée(s) avec succès',
                'data' => [
                    'sent_count' => count($notifications),
                    'notifications' => $notifications,
                    'summary' => [
                        'title' => $request->title,
                        'type' => $request->type,
                        'target_users_count' => count($targetUsers)
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi des notifications',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Envoyer une notification promotionnelle à tous les clients actifs (ADMIN ONLY)
     * 
     * @param Request $request - Données de la promotion
     * @return JsonResponse - Notifications envoyées
     */
    public function sendPromotion(Request $request): JsonResponse
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
                'title' => 'required|string|max:255',
                'message' => 'required|string|max:1000',
                'promotion_data' => 'nullable|array',
                'promotion_data.discount' => 'nullable|string',
                'promotion_data.valid_until' => 'nullable|date',
                'promotion_data.code' => 'nullable|string'
            ], [
                'title.required' => 'Le titre est requis',
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères',
                'message.required' => 'Le message est requis',
                'message.max' => 'Le message ne peut pas dépasser 1000 caractères',
                'promotion_data.valid_until.date' => 'La date de fin de validité doit être une date valide'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer tous les clients actifs
            $activeClients = User::where('role', 'client')
                ->where('is_active', true)
                ->get();

            if ($activeClients->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun client actif trouvé'
                ], 422);
            }

            $notifications = [];
            $errors = [];

            // Préparer les données de la promotion
            $promotionData = array_merge($request->promotion_data ?? [], [
                'sent_at' => now()->toISOString(),
                'admin_sender' => $request->user()->name
            ]);

            // Créer les notifications pour chaque client
            foreach ($activeClients as $client) {
                try {
                    $notification = Notification::create([
                        'user_id' => $client->id,
                        'title' => $request->title,
                        'message' => $request->message,
                        'type' => 'promotion',
                        'data' => $promotionData,
                        'is_read' => false
                    ]);

                    $notifications[] = [
                        'id' => $notification->id,
                        'user_id' => $client->id,
                        'user_name' => $client->name,
                        'created_at' => $notification->created_at
                    ];

                } catch (\Exception $e) {
                    $errors[] = "Erreur pour le client {$client->name}: " . $e->getMessage();
                }
            }

            // Si des erreurs sont survenues, retourner les erreurs
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreurs lors de l\'envoi de certaines notifications',
                    'errors' => $errors,
                    'sent_notifications' => $notifications
                ], 422);
            }

        return response()->json([
            'success' => true,
                'message' => 'Promotion envoyée à ' . count($notifications) . ' client(s) avec succès',
                'data' => [
                    'sent_count' => count($notifications),
                    'total_clients' => $activeClients->count(),
                    'promotion' => [
                        'title' => $request->title,
                        'data' => $promotionData
                    ],
                    'summary' => [
                        'success_rate' => round((count($notifications) / $activeClients->count()) * 100, 2) . '%',
                        'sent_at' => now()
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi de la promotion',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
