<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminCashierController extends Controller
{
    public function index(): JsonResponse
    {
        $cashiers = User::query()
            ->where('role', 'caissiere')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->formatCashier($user));

        return response()->json([
            'success' => true,
            'message' => 'Caissiers récupérés avec succès',
            'data' => [
                'cashiers' => $cashiers,
                'summary' => [
                    'total' => $cashiers->count(),
                    'active' => $cashiers->where('is_active', true)->count(),
                    'with_pin' => $cashiers->where('has_pin', true)->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:30',
            'pin' => 'nullable|string|regex:/^\d{4}$/',
        ], [
            'name.required' => 'Le nom est obligatoire',
            'email.required' => 'L\'email de connexion est obligatoire',
            'email.unique' => 'Cet email est déjà utilisé',
            'password.required' => 'Le mot de passe est obligatoire',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
            'pin.regex' => 'Le PIN caisse doit contenir exactement 4 chiffres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $cashier = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'whatsapp_phone' => $request->phone,
                'role' => 'caissiere',
                'is_active' => true,
            ]);

            if ($request->filled('pin')) {
                $cashier->pos_pin = Hash::make($request->pin);
                $cashier->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Caissier créé avec succès',
                'data' => $this->formatCashier($cashier->fresh()),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du caissier',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $cashier = $this->findCashier($id);

        if (!$cashier) {
            return response()->json([
                'success' => false,
                'message' => 'Caissier introuvable',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $cashier->id,
            'password' => 'sometimes|string|min:8',
            'phone' => 'nullable|string|max:30',
            'pin' => 'nullable|string|regex:/^\d{4}$/',
        ], [
            'email.unique' => 'Cet email est déjà utilisé',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
            'pin.regex' => 'Le PIN caisse doit contenir exactement 4 chiffres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if ($request->has('name')) {
                $cashier->name = $request->name;
            }

            if ($request->has('email')) {
                $cashier->email = $request->email;
            }

            if ($request->filled('password')) {
                $cashier->password = Hash::make($request->password);
            }

            if ($request->has('phone')) {
                $cashier->phone = $request->phone;
                $cashier->whatsapp_phone = $request->phone;
            }

            if ($request->filled('pin')) {
                $cashier->pos_pin = Hash::make($request->pin);
            }

            $cashier->save();

            return response()->json([
                'success' => true,
                'message' => 'Caissier mis à jour avec succès',
                'data' => $this->formatCashier($cashier->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $cashier = $this->findCashier($id);

        if (!$cashier) {
            return response()->json([
                'success' => false,
                'message' => 'Caissier introuvable',
            ], 404);
        }

        $cashier->is_active = !$cashier->is_active;
        $cashier->save();

        if (!$cashier->is_active) {
            $cashier->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $cashier->is_active
                ? 'Caissier activé'
                : 'Caissier désactivé',
            'data' => $this->formatCashier($cashier),
        ]);
    }

    private function findCashier(int $id): ?User
    {
        return User::query()
            ->where('role', 'caissiere')
            ->find($id);
    }

    private function formatCashier(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_phone' => $user->whatsapp_phone,
            'is_active' => (bool) $user->is_active,
            'has_pin' => !empty($user->pos_pin),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
