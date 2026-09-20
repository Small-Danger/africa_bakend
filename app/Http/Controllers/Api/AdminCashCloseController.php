<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Services\CashCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCashCloseController extends Controller
{
    public function index(): JsonResponse
    {
        $service = app(CashCloseService::class);
        $sessions = CashSession::query()
            ->with('cashier')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        $closed = $sessions->whereNotNull('closed_at');

        return response()->json([
            'success' => true,
            'message' => 'Clôtures récupérées avec succès',
            'data' => [
                'items' => $sessions->map(fn (CashSession $session) => $service->present($session))->values(),
                'summary' => [
                    'sessions_count' => $sessions->count(),
                    'closed_count' => $closed->count(),
                    'open_count' => $sessions->count() - $closed->count(),
                    'sales_total' => (int) $closed->sum(fn (CashSession $session) => (int) ($session->report['sales_total'] ?? 0)),
                    'discrepancy_total' => (int) $closed->sum(fn (CashSession $session) => (int) round((float) ($session->discrepancy ?? 0))),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $session = CashSession::query()->with('cashier')->find($id);
        if (! $session) {
            return response()->json([
                'success' => false,
                'message' => 'Session introuvable',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Clôture récupérée avec succès',
            'data' => [
                'session' => app(CashCloseService::class)->present($session),
            ],
        ]);
    }
}
