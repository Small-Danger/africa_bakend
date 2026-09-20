<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Services\CashCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class PosCashSessionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $session = CashSession::open()
            ->where('cashier_id', $request->user()->id)
            ->latest('opened_at')
            ->first();

        if (! $session) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Aucune session de caisse ouverte',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => app(CashCloseService::class)->present($session),
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
                'data' => app(CashCloseService::class)->present($existing),
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

        $session = CashSession::query()->create([
            'cashier_id' => $request->user()->id,
            'opening_amount' => $request->opening_amount,
            'opened_at' => now(),
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session de caisse ouverte',
            'data' => app(CashCloseService::class)->present($session),
        ], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $session = CashSession::open()
            ->where('cashier_id', $request->user()->id)
            ->first();

        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune session de caisse ouverte',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'closing_amount_counted' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ], [
            'closing_amount_counted.required' => 'Indiquez le montant compté en caisse',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $closed = app(CashCloseService::class)->close(
                $session,
                (int) round((float) $validator->validated()['closing_amount_counted']),
                $validator->validated()['notes'] ?? null,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Session de caisse fermée',
            'data' => app(CashCloseService::class)->present($closed),
        ]);
    }
}
