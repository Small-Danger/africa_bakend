<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PosCashSessionController extends Controller
{
    use FormatsPosOrders;
    public function current(Request $request): JsonResponse
    {
        $session = CashSession::open()
            ->where('cashier_id', $request->user()->id)
            ->latest('opened_at')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Aucune session de caisse ouverte',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatSession($session),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $existing = CashSession::open()
            ->where('cashier_id', $request->user()->id)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Une session de caisse est déjà ouverte',
                'data' => $this->formatSession($existing),
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'opening_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ], [
            'opening_amount.required' => 'Le fond de caisse initial est obligatoire',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $session = CashSession::create([
            'cashier_id' => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at' => now(),
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session de caisse ouverte',
            'data' => $this->formatSession($session),
        ], 201);
    }

    public function close(Request $request): JsonResponse
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
            'closing_amount_counted' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $closingExpected = $this->calculateClosingExpected($session);
        $closingCounted = (float) $request->closing_amount_counted;
        $discrepancy = $closingCounted - $closingExpected;

        $session->update([
            'closing_amount_expected' => $closingExpected,
            'closing_amount_counted' => $closingCounted,
            'discrepancy' => $discrepancy,
            'closed_at' => now(),
            'notes' => $request->notes ?? $session->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session de caisse fermée',
            'data' => $this->formatSession($session->fresh()),
        ]);
    }

    private function formatSession(CashSession $session): array
    {
        return [
            'id' => $session->id,
            'cashier_id' => $session->cashier_id,
            'opening_amount' => $session->opening_amount,
            'closing_amount_expected' => $session->closing_amount_expected,
            'closing_amount_counted' => $session->closing_amount_counted,
            'discrepancy' => $session->discrepancy,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'notes' => $session->notes,
            'is_open' => $session->isOpen(),
        ];
    }
}
