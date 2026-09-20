<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
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

    public function receipts(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $stock = app(StockService::class);
        $monthStart = now()->startOfMonth();

        $receipts = StockReceipt::query()
            ->with(['items.variant.product', 'user', 'cancelledBy'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $monthReceipts = StockReceipt::query()->active()->where('received_at', '>=', $monthStart);
        $summary = [
            'receipts_count' => (clone $monthReceipts)->count(),
            'units_received' => (int) StockReceiptItem::query()
                ->whereHas('receipt', fn ($query) => $query->active()->where('received_at', '>=', $monthStart))
                ->sum('quantity'),
        ];

        if ($viewer->hasPermissionTo(Permissions::FINANCE_VIEW)) {
            $summary['merchandise_cost'] = (int) (clone $monthReceipts)->sum('merchandise_cost');
            $summary['shipping_cost'] = (int) (clone $monthReceipts)->sum('shipping_cost');
            $summary['invested'] = $summary['merchandise_cost'] + $summary['shipping_cost'];
        }

        return response()->json([
            'success' => true,
            'message' => 'Réceptions récupérées avec succès',
            'data' => [
                'items' => $receipts->map(fn (StockReceipt $receipt) => $stock->presentReceipt($receipt, $viewer))->values(),
                'summary' => $summary,
                'can_adjust' => $viewer->hasPermissionTo(Permissions::STOCK_ADJUST),
                'can_view_finance' => $viewer->hasPermissionTo(Permissions::FINANCE_VIEW),
            ],
        ]);
    }

    public function storeReceipt(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->receiptRules(), $this->receiptMessages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $receipt = app(StockService::class)->receiveReceipt(
                $validator->validated(),
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
            'message' => 'Arrivage enregistré, le stock a été mis à jour',
            'data' => [
                'receipt' => app(StockService::class)->presentReceipt($receipt, $request->user()),
            ],
        ], 201);
    }

    public function updateReceipt(Request $request, int $receiptId): JsonResponse
    {
        $receipt = StockReceipt::query()->find($receiptId);
        if (! $receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Arrivage introuvable',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->receiptRules(), $this->receiptMessages());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $updated = app(StockService::class)->updateReceipt(
                $receipt,
                $validator->validated(),
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
            'message' => 'Arrivage corrigé, le stock a été ajusté',
            'data' => [
                'receipt' => app(StockService::class)->presentReceipt($updated, $request->user()),
            ],
        ]);
    }

    public function cancelReceipt(Request $request, int $receiptId): JsonResponse
    {
        $receipt = StockReceipt::query()->find($receiptId);
        if (! $receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Arrivage introuvable',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'confirmation' => 'required|string',
            'reason' => 'nullable|string|max:255',
        ], [
            'confirmation.required' => 'Tapez DELETE pour confirmer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $cancelled = app(StockService::class)->cancelReceipt(
                $receipt,
                $request->user(),
                (string) $validator->validated()['confirmation'],
                $validator->validated()['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Arrivage annulé : le stock a été retiré, l’historique est conservé',
            'data' => [
                'receipt' => app(StockService::class)->presentReceipt($cancelled, $request->user()),
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function receiptRules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|integer|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|integer|min:0',
            'merchandise_cost' => 'nullable|integer|min:0',
            'shipping_cost' => 'nullable|integer|min:0',
            'note' => 'nullable|string|max:255',
            'received_at' => 'nullable|date',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function receiptMessages(): array
    {
        return [
            'items.required' => 'Ajoutez au moins un produit reçu',
            'items.min' => 'Ajoutez au moins un produit reçu',
            'items.*.quantity.min' => 'La quantité reçue doit être au moins 1',
            'items.*.variant_id.exists' => 'Une variante est introuvable',
        ];
    }
}
