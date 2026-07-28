<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Services\PosClientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosClientController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));

        if ($phone === '') {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'status' => 'idle',
                    'count' => 0,
                ],
            ]);
        }

        if (strlen(PosClientResolver::normalizePhone($phone)) < 4) {
            return response()->json([
                'success' => true,
                'data' => [],
                'meta' => [
                    'status' => 'too_short',
                    'count' => 0,
                    'message' => 'Saisissez au moins 4 chiffres',
                ],
            ]);
        }

        $clients = PosClientResolver::searchByPhone($phone);
        $exactMatch = PosClientResolver::findExactByPhone($phone);

        $status = 'not_found';
        if ($exactMatch) {
            $status = 'exact';
        } elseif ($clients->isNotEmpty()) {
            $status = 'partial';
        }

        return response()->json([
            'success' => true,
            'data' => $clients->values(),
            'meta' => [
                'status' => $status,
                'count' => $clients->count(),
                'exact_match' => $exactMatch
                    ? PosClientResolver::formatClient($exactMatch)
                    : null,
                'message' => match ($status) {
                    'exact' => 'Client existant trouvé',
                    'partial' => 'Plusieurs clients correspondent — sélectionnez',
                    default => 'Nouveau numéro — saisissez le nom pour l\'enregistrer',
                },
            ],
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
            $existing = PosClientResolver::findExactByPhone($phone);

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Client existant trouvé',
                    'data' => PosClientResolver::formatClient($existing),
                    'meta' => ['created' => false],
                ]);
            }
        }

        $client = PosClientResolver::createClient($request->name, $phone);

        return response()->json([
            'success' => true,
            'message' => 'Client enregistré',
            'data' => PosClientResolver::formatClient($client),
            'meta' => ['created' => true],
        ], 201);
    }
}
