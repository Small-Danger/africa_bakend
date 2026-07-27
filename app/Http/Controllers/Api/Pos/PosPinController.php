<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PosPinController extends Controller
{
    public function setPin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'pin' => 'required|string|regex:/^\d{4}$/',
            'pin_confirmation' => 'required|same:pin',
        ], [
            'pin.regex' => 'Le PIN doit contenir exactement 4 chiffres',
            'pin_confirmation.same' => 'La confirmation du PIN ne correspond pas',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!$user->password || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect',
            ], 401);
        }

        $user->update([
            'pos_pin' => Hash::make($request->pin),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'PIN caisse configuré avec succès',
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'pin' => 'required|string|regex:/^\d{4}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::find($request->user_id);

        if (!$user || !$user->canAccessPos() || !$user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non autorisé',
            ], 403);
        }

        if (!$user->pos_pin || !Hash::check($request->pin, $user->pos_pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN incorrect',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Caisse déverrouillée',
            'data' => [
                'user_id' => $user->id,
                'name' => $user->name,
            ],
        ]);
    }

    public function hasPin(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'has_pin' => !empty($user->pos_pin),
            ],
        ]);
    }
}
