<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\StockService;
use App\Stock\StockState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AdminStockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $status = (string) $request->string('status');
        $search = trim((string) $request->string('search'));

        $query = ProductVariant::query()
            ->with(['product.category'])
            ->whereHas('product', fn ($productQuery) => $productQuery->where('is_active', true))
            ->orderBy('product_id')
            ->orderBy('sort_order');

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($inner) use ($like) {
                $inner->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$like])
                    ->orWhereHas('product', fn ($productQuery) => $productQuery->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        $stock = app(StockService::class);
        $rows = $query->get()->map(fn (ProductVariant $variant) => $stock->presentInventory($variant, $viewer));

        $summary = [
            'total' => $rows->count(),
            'needs_inventory' => $rows->where('needs_inventory', true)->count(),
            'en_stock' => $rows->where('stock_status', StockState::EN_STOCK)->where('needs_inventory', false)->count(),
            'sur_commande' => $rows->where('stock_status', StockState::SUR_COMMANDE)->count(),
            'rupture' => $rows->where('stock_status', StockState::RUPTURE)->count(),
            'low' => $rows->where('is_low', true)->count(),
        ];

        $items = match ($status) {
            'needs_inventory' => $rows->where('needs_inventory', true),
            'en_stock' => $rows->where('stock_status', StockState::EN_STOCK)->where('needs_inventory', false),
            'sur_commande' => $rows->where('stock_status', StockState::SUR_COMMANDE),
            'rupture' => $rows->where('stock_status', StockState::RUPTURE),
            'low' => $rows->where('is_low', true),
            default => $rows,
        };

        return response()->json([
            'success' => true,
            'message' => 'Stock récupéré avec succès',
            'data' => [
                'items' => $items->values(),
                'summary' => $summary,
                'can_view_quantities' => $viewer->hasPermissionTo(Permissions::STOCK_VIEW_QUANTITIES),
                'can_adjust' => $viewer->hasPermissionTo(Permissions::STOCK_ADJUST),
            ],
        ]);
    }

    public function update(Request $request, int $variantId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:0',
            'reason' => 'nullable|string|max:255',
        ], [
            'quantity.required' => 'Indiquez la quantité comptée',
            'quantity.integer' => 'La quantité doit être un nombre entier',
            'quantity.min' => 'La quantité ne peut pas être négative',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $variant = ProductVariant::query()->with(['product.category'])->find($variantId);

        if (! $variant) {
            return response()->json([
                'success' => false,
                'message' => 'Variante introuvable',
            ], 404);
        }

        $quantity = (int) $validator->validated()['quantity'];
        $reason = trim((string) ($validator->validated()['reason'] ?? ''));
        $fromNull = $variant->stock_quantity === null;
        $reason = $reason !== ''
            ? $reason
            : ($fromNull ? 'Inventaire initial' : 'Correction de stock');

        try {
            $updated = app(StockService::class)->adjust(
                $variant,
                $quantity,
                $request->user(),
                $reason,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $updated->load(['product.category']);

        return response()->json([
            'success' => true,
            'message' => $fromNull ? 'Inventaire enregistré' : 'Stock corrigé',
            'data' => [
                'item' => app(StockService::class)->presentInventory($updated, $request->user()),
            ],
        ]);
    }
}
