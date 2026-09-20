<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Roles;
use App\Authorization\StaffAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminTeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $visible = StaffAccess::visibleRoles($actor);

        $members = User::query()
            ->whereIn('role', $visible)
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->formatMember($user, $actor));

        $assignable = StaffAccess::assignableRoles($actor);

        return response()->json([
            'success' => true,
            'message' => 'Équipe récupérée avec succès',
            'data' => [
                'members' => $members,
                'assignable_roles' => collect($assignable)->map(fn (string $role) => [
                    'name' => $role,
                    'label' => Roles::label($role),
                ])->values(),
                'summary' => [
                    'total' => $members->count(),
                    'active' => $members->where('is_active', true)->count(),
                    'gerants' => $members->where('role', Roles::GERANT)->count(),
                    'secretaires' => $members->where('role', Roles::SECRETAIRE)->count(),
                    'caissiers' => $members->where('role', Roles::CAISSIERE)->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        $assignable = StaffAccess::assignableRoles($actor);

        if ($assignable === []) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:'.implode(',', $assignable),
            'phone' => 'nullable|string|max:30',
            'pin' => 'nullable|string|regex:/^\d{4}$/',
        ], [
            'name.required' => 'Le nom est obligatoire',
            'email.required' => 'L\'email de connexion est obligatoire',
            'email.unique' => 'Cet email est déjà utilisé',
            'password.required' => 'Le mot de passe est obligatoire',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
            'role.required' => 'Le rôle est obligatoire',
            'role.in' => 'Vous n\'êtes pas autorisé à attribuer ce rôle',
            'pin.regex' => 'Le PIN caisse doit contenir exactement 4 chiffres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $member = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'phone' => $request->phone,
            'whatsapp_phone' => $request->phone,
            'role' => $request->role,
            'is_active' => true,
        ]);

        if ($request->filled('pin') && in_array($member->role, Roles::posPinRoles(), true)) {
            $member->pos_pin = Hash::make($request->pin);
            $member->save();
        }

        ActivityLogger::record(
            $actor,
            'team.created',
            'Compte '.Roles::label($member->role).' créé : '.$member->name,
            $member,
            [
                'email' => $member->email,
                'role' => $member->role,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Compte créé avec succès',
            'data' => $this->formatMember($member->fresh(), $actor),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $member = $this->findVisibleMember($actor, $id);

        if (! $member) {
            return response()->json([
                'success' => false,
                'message' => 'Membre introuvable',
            ], 404);
        }

        if (! StaffAccess::canManage($actor, $member)) {
            return $this->forbidden();
        }

        $assignable = StaffAccess::assignableRoles($actor);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$member->id,
            'password' => 'sometimes|string|min:8',
            'role' => 'sometimes|in:'.implode(',', $assignable),
            'phone' => 'nullable|string|max:30',
            'pin' => 'nullable|string|regex:/^\d{4}$/',
        ], [
            'email.unique' => 'Cet email est déjà utilisé',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
            'role.in' => 'Vous n\'êtes pas autorisé à attribuer ce rôle',
            'pin.regex' => 'Le PIN caisse doit contenir exactement 4 chiffres',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('name')) {
            $member->name = $request->name;
        }
        if ($request->has('email')) {
            $member->email = $request->email;
        }
        if ($request->filled('password')) {
            $member->password = $request->password;
        }
        if ($request->has('role')) {
            $member->role = $request->role;
        }
        if ($request->has('phone')) {
            $member->phone = $request->phone;
            $member->whatsapp_phone = $request->phone;
        }
        if ($request->filled('pin') && in_array($member->role, Roles::posPinRoles(), true)) {
            $member->pos_pin = Hash::make($request->pin);
        }

        $changes = [];
        foreach (['name', 'email', 'role', 'phone'] as $field) {
            if ($member->isDirty($field)) {
                $changes[$field] = [
                    'from' => $member->getOriginal($field),
                    'to' => $member->{$field},
                ];
            }
        }
        if ($request->filled('password')) {
            $changes['password'] = ['from' => '(inchangé)', 'to' => '(modifié)'];
        }
        if ($request->filled('pin')) {
            $changes['pin'] = ['from' => '(inchangé)', 'to' => '(modifié)'];
        }

        $member->save();

        if ($changes !== []) {
            ActivityLogger::record(
                $actor,
                'team.updated',
                'Compte modifié : '.$member->name,
                $member,
                ['changes' => $changes],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Compte mis à jour avec succès',
            'data' => $this->formatMember($member->fresh(), $actor),
        ]);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $member = $this->findVisibleMember($actor, $id);

        if (! $member) {
            return response()->json([
                'success' => false,
                'message' => 'Membre introuvable',
            ], 404);
        }

        if (! StaffAccess::canManage($actor, $member)) {
            return $this->forbidden();
        }

        $member->is_active = ! $member->is_active;
        $member->save();

        if (! $member->is_active) {
            $member->tokens()->delete();
        }

        ActivityLogger::record(
            $actor,
            $member->is_active ? 'team.activated' : 'team.deactivated',
            ($member->is_active ? 'Compte activé' : 'Compte désactivé').' : '.$member->name,
            $member,
            ['is_active' => $member->is_active],
        );

        return response()->json([
            'success' => true,
            'message' => $member->is_active ? 'Compte activé' : 'Compte désactivé',
            'data' => $this->formatMember($member, $actor),
        ]);
    }

    private function findVisibleMember(User $actor, int $id): ?User
    {
        return User::query()
            ->whereIn('role', StaffAccess::visibleRoles($actor))
            ->find($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMember(User $user, User $actor): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_phone' => $user->whatsapp_phone,
            'role' => $user->role,
            'role_label' => Roles::label($user->role),
            'is_active' => (bool) $user->is_active,
            'has_pin' => ! empty($user->pos_pin),
            'can_manage' => StaffAccess::canManage($actor, $user),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Accès non autorisé',
        ], 403);
    }
}
