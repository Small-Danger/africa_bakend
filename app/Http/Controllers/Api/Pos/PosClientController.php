<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PosClientController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));

        if ($phone === '') {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $clients = User::query()
            ->where('role', 'client')
            ->where(function ($q) use ($phone) {
                $q->where('phone', 'ilike', "%{$phone}%")
                    ->orWhere('whatsapp_phone', 'ilike', "%{$phone}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'phone', 'whatsapp_phone', 'email']);

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }

    public function quickCreate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
        ], [
            'name.required' => 'Le nom du client est obligatoire',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->phone ? trim($request->phone) : null;

        if ($phone) {
            $existing = User::query()
                ->where('role', 'client')
                ->where(function ($q) use ($phone) {
                    $q->where('phone', $phone)->orWhere('whatsapp_phone', $phone);
                })
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Client existant trouvé',
                    'data' => $existing->only(['id', 'name', 'phone', 'whatsapp_phone', 'email']),
                ]);
            }
        }

        $client = User::create([
            'name' => $request->name,
            'phone' => $phone,
            'whatsapp_phone' => $phone,
            'email' => 'pos_' . time() . '_' . Str::random(6) . '@afrikraga.local',
            'password' => null,
            'role' => 'client',
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Client créé',
            'data' => $client->only(['id', 'name', 'phone', 'whatsapp_phone', 'email']),
        ], 201);
    }
}
