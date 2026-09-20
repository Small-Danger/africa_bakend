<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\StockReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminFinanceController extends Controller
{
    public function month(Request $request): JsonResponse
    {
        $year = (int) $request->integer('year', now()->year);
        $month = (int) $request->integer('month', now()->month);

        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            return response()->json([
                'success' => false,
                'message' => 'Mois invalide',
            ], 422);
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $receipts = StockReceipt::query()->whereBetween('received_at', [$start, $end]);
        $merchandise = (int) (clone $receipts)->sum('merchandise_cost');
        $shipping = (int) (clone $receipts)->sum('shipping_cost');
        $invested = $merchandise + $shipping;

        $salesQuery = Order::query()
            ->where('status', '!=', 'annulée')
            ->whereBetween('created_at', [$start, $end]);
        $sales = (int) round((float) (clone $salesQuery)->sum('total_amount'));
        $ordersCount = (clone $salesQuery)->count();

        $remaining = $invested - $sales;
        $progress = $invested > 0
            ? (int) min(100, round($sales / $invested * 100))
            : ($sales > 0 ? 100 : 0);

        return response()->json([
            'success' => true,
            'message' => 'Rapport du mois récupéré avec succès',
            'data' => [
                'year' => $year,
                'month' => $month,
                'label' => $start->locale('fr')->translatedFormat('F Y'),
                'invested' => $invested,
                'merchandise_cost' => $merchandise,
                'shipping_cost' => $shipping,
                'receipts_count' => (clone $receipts)->count(),
                'sales' => $sales,
                'orders_count' => $ordersCount,
                'remaining' => $remaining,
                'progress_percent' => $progress,
                'recovered' => $invested > 0 && $sales >= $invested,
            ],
        ]);
    }
}
