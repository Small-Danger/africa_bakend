<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouveau client
     * 
     * @param Request $request - Données d'inscription (nom, email/téléphone, mot de passe)
     * @return JsonResponse - Token de connexion et informations utilisateur
     */
    public function register(Request $request): JsonResponse
    {
        // Validation des données d'inscription
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email', // Email optionnel mais unique si fourni
            'whatsapp_phone' => 'required|string|unique:users,whatsapp_phone', // Téléphone WhatsApp obligatoire
            'password' => 'required|string|min:8|confirmed', // Mot de passe avec confirmation
        ], [
            'name.required' => 'Le nom est obligatoire',
            'whatsapp_phone.required' => 'Le numéro WhatsApp est obligatoire',
            'whatsapp_phone.unique' => 'Ce numéro WhatsApp est déjà utilisé',
            'email.unique' => 'Cet email est déjà utilisé',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas'
        ]);

        // Si validation échoue, retourner les erreurs
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Créer le nouvel utilisateur
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'whatsapp_phone' => $request->whatsapp_phone,
                'password' => Hash::make($request->password),
                'role' => 'client' // Par défaut, tous les nouveaux utilisateurs sont des clients
            ]);

            // Créer le token Sanctum pour la connexion automatique
            $token = $user->createToken('auth-token', ['client'])->plainTextToken;

            // Retourner la réponse de succès
            return response()->json([
                'success' => true,
                'message' => 'Inscription réussie ! Bienvenue ' . $user->name,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'whatsapp_phone' => $user->whatsapp_phone,
                        'role' => $user->role
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ], 201);

        } catch (\Exception $e) {
            // En cas d'erreur, retourner un message d'erreur
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'inscription',
                'error' => 'Une erreur est survenue, veuillez réessayer'
            ], 500);
        }
    }

    /**
     * Connexion d'un utilisateur (client ou admin)
     * 
     * @param Request $request - Données de connexion (email/téléphone, mot de passe)
     * @return JsonResponse - Token de connexion et informations utilisateur
     */
    public function login(Request $request): JsonResponse
    {
        // Debug: Log des données reçues
        \Log::info('Login attempt', [
            'all_data' => $request->all(),
            'email' => $request->email,
            'whatsapp_phone' => $request->whatsapp_phone,
            'password' => $request->password ? '***' : 'null',
            'headers' => $request->headers->all()
        ]);

        // Validation des données de connexion
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|email', // Email optionnel
            'whatsapp_phone' => 'nullable|string', // Téléphone optionnel
            'password' => 'required|string'
        ], [
            'password.required' => 'Le mot de passe est obligatoire'
        ]);

        // Au moins un identifiant doit être fourni (email OU téléphone)
        if (!$request->email && !$request->whatsapp_phone) {
            \Log::warning('Login failed: No email or whatsapp_phone provided', [
                'request_data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Email ou numéro WhatsApp requis',
                'debug' => [
                    'received_data' => $request->all(),
                    'email_present' => $request->has('email'),
                    'whatsapp_present' => $request->has('whatsapp_phone')
                ]
            ], 422);
        }

        // Si validation échoue, retourner les erreurs
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Construire la requête de recherche
            $query = User::query();
            
            if ($request->email) {
                $query->where('email', $request->email);
            } elseif ($request->whatsapp_phone) {
                $query->where('whatsapp_phone', $request->whatsapp_phone);
            }

            // Récupérer l'utilisateur
            $user = $query->first();

            // Vérifier si l'utilisateur existe et le mot de passe est correct
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects'
                ], 401);
            }

            // Vérifier si l'utilisateur est actif
            if (!$user->is_active ?? true) {
                return response()->json([
                    'success' => false,
                    'message' => 'Compte désactivé'
                ], 403);
            }

            // Supprimer tous les anciens tokens de l'utilisateur (sécurité)
            $user->tokens()->delete();

            // Créer le nouveau token Sanctum avec les bonnes permissions
            $abilities = $user->isAdmin() ? ['admin'] : ['client'];
            $token = $user->createToken('auth-token', $abilities)->plainTextToken;

            // Retourner la réponse de succès
            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie ! Bonjour ' . $user->name,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'whatsapp_phone' => $user->whatsapp_phone,
                        'role' => $user->role,
                        'is_admin' => $user->isAdmin()
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'abilities' => $abilities
                ]
            ], 200);

        } catch (\Exception $e) {
            // En cas d'erreur, retourner un message d'erreur
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion',
                'error' => 'Une erreur est survenue, veuillez réessayer'
            ], 500);
        }
    }

    /**
     * Déconnexion de l'utilisateur connecté
     * 
     * @param Request $request - Requête avec le token d'authentification
     * @return JsonResponse - Message de déconnexion
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Récupérer l'utilisateur connecté
            $user = $request->user();
            
            if ($user) {
                // Supprimer le token actuel
                $request->user()->currentAccessToken()->delete();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Déconnexion réussie'
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun utilisateur connecté'
                ], 401);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Récupérer les informations du profil de l'utilisateur connecté
     * 
     * @param Request $request - Requête avec le token d'authentification
     * @return JsonResponse - Informations du profil utilisateur
     */
    public function profile(Request $request): JsonResponse
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

            // Retourner les informations du profil
            return response()->json([
                'success' => true,
                'message' => 'Profil récupéré avec succès',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'whatsapp_phone' => $user->whatsapp_phone,
                        'role' => $user->role,
                        'is_admin' => $user->isAdmin(),
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du profil',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Mettre à jour le profil de l'utilisateur connecté
     * 
     * @param Request $request - Requête avec les nouvelles données
     * @return JsonResponse - Profil mis à jour
     */
    public function updateProfile(Request $request): JsonResponse
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

            // Validation des données de mise à jour
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $user->id,
                'whatsapp_phone' => 'sometimes|string|unique:users,whatsapp_phone,' . $user->id,
                'current_password' => 'required_with:new_password|string',
                'new_password' => 'sometimes|string|min:8|confirmed'
            ], [
                'email.unique' => 'Cet email est déjà utilisé',
                'whatsapp_phone.unique' => 'Ce numéro WhatsApp est déjà utilisé',
                'current_password.required_with' => 'Le mot de passe actuel est requis pour changer le mot de passe',
                'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères',
                'new_password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier le mot de passe actuel si changement de mot de passe
            if ($request->new_password && !Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mot de passe actuel incorrect'
                ], 401);
            }

            // Mettre à jour les champs fournis
            if ($request->has('name')) {
                $user->name = $request->name;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            if ($request->has('whatsapp_phone')) {
                $user->whatsapp_phone = $request->whatsapp_phone;
            }
            if ($request->has('new_password')) {
                $user->password = Hash::make($request->new_password);
            }

            // Sauvegarder les modifications
            $user->save();

            // Retourner le profil mis à jour
            return response()->json([
                'success' => true,
                'message' => 'Profil mis à jour avec succès',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'whatsapp_phone' => $user->whatsapp_phone,
                        'role' => $user->role,
                        'is_admin' => $user->isAdmin(),
                        'updated_at' => $user->updated_at
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du profil',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Réinitialisation du mot de passe (optionnel)
     * 
     * @param Request $request - Requête avec l'email/téléphone
     * @return JsonResponse - Message de confirmation
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        // Validation de la requête
        $validator = Validator::make($request->all(), [
            'email' => 'nullable|email',
            'whatsapp_phone' => 'nullable|string'
        ]);

        // Au moins un identifiant doit être fourni
        if (!$request->email && !$request->whatsapp_phone) {
            return response()->json([
                'success' => false,
                'message' => 'Email ou numéro WhatsApp requis'
            ], 422);
        }

        // Si validation échoue, retourner les erreurs
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Construire la requête de recherche
            $query = User::query();
            
            if ($request->email) {
                $query->where('email', $request->email);
            } elseif ($request->whatsapp_phone) {
                $query->where('whatsapp_phone', $request->whatsapp_phone);
            }

            // Récupérer l'utilisateur
            $user = $query->first();

            if ($user) {
                // Ici, vous pourriez implémenter l'envoi d'un lien de réinitialisation
                // ou d'un code par WhatsApp/SMS
                
                return response()->json([
                    'success' => true,
                    'message' => 'Instructions de réinitialisation envoyées',
                    'note' => 'Cette fonctionnalité sera implémentée selon vos besoins'
                ], 200);
            } else {
                // Pour des raisons de sécurité, ne pas révéler si l'utilisateur existe
                return response()->json([
                    'success' => true,
                    'message' => 'Instructions de réinitialisation envoyées'
                ], 200);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * [ADMIN] Lister tous les clients avec pagination
     * 
     * @param Request $request - Requête avec pagination et filtres
     * @return JsonResponse - Liste des clients
     */
    public function listClients(Request $request): JsonResponse
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

            // Construire la requête
            $query = User::clients();

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

            // Récupérer les clients avec pagination
            $clients = $query->orderBy('created_at', 'desc')
                            ->paginate($perPage);

            // Formater la réponse
            $clients->getCollection()->transform(function($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email,
                    'whatsapp_phone' => $client->whatsapp_phone,
                    'is_active' => $client->is_active,
                    'status' => $client->is_active ? 'Actif' : 'Bloqué',
                    'created_at' => $client->created_at->format('d/m/Y H:i'),
                    'orders_count' => $client->orders()->count(),
                    'last_order' => $client->orders()->latest()->first()?->created_at?->format('d/m/Y H:i') ?? 'Aucune commande'
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
                        'total' => $clients->total()
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
     * [ADMIN] Bloquer/Débloquer un compte client
     * 
     * @param Request $request - Requête avec l'ID du client et l'action
     * @return JsonResponse - Confirmation de l'action
     */
    public function toggleClientStatus(Request $request): JsonResponse
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
     * [ADMIN] Obtenir les statistiques des clients
     * 
     * @param Request $request - Requête authentifiée
     * @return JsonResponse - Statistiques des clients
     */
    public function getClientStats(Request $request): JsonResponse
    {
        try {
            // Vérifier que l'utilisateur connecté est un admin
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accès non autorisé'
                ], 403);
            }

            // Calculer les statistiques
            $totalClients = User::clients()->count();
            $activeClients = User::clients()->active()->count();
            $blockedClients = User::clients()->inactive()->count();
            $newClientsThisMonth = User::clients()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'Statistiques des clients récupérées avec succès',
                'data' => [
                    'total_clients' => $totalClients,
                    'active_clients' => $activeClients,
                    'blocked_clients' => $blockedClients,
                    'new_clients_this_month' => $newClientsThisMonth,
                    'active_percentage' => $totalClients > 0 ? round(($activeClients / $totalClients) * 100, 2) : 0
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
