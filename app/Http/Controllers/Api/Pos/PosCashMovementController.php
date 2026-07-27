<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use App\Models\CashSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosCashMovementController extends Controller
{
    public function currentSession(Request $request): JsonResponse
    {
        $session = CashSession::open()
            ->where('cashier_id', $request->user()->id)
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune session de caisse ouverte',
            ], 422);
        }

        $movements = $session->movements()
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($m) => $this->formatMovement($m));

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $session = CashSession::open()
            ->where('cashier_id', $request->user()->id)
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune session de caisse ouverte',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:entree,sortie',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ], [
            'reason.required' => 'Le motif est obligatoire',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $movement = CashMovement::create([
            'cash_session_id' => $session->id,
            'created_by' => $request->user()->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'reason' => $request->reason,
        ]);

        $movement->load('createdBy:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Mouvement enregistré',
            'data' => $this->formatMovement($movement),
        ], 201);
    }

    private function formatMovement(CashMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'type' => $movement->type,
            'amount' => $movement->amount,
            'reason' => $movement->reason,
            'created_by' => $movement->createdBy ? [
                'id' => $movement->createdBy->id,
                'name' => $movement->createdBy->name,
            ] : null,
            'created_at' => $movement->created_at,
        ];
    }
}
