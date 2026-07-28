<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PosClientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosOrderController extends Controller
{
    use FormatsPosOrders;

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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            'client_id' => 'nullable|exists:users,id',
            'client_phone' => 'nullable|string|max:30',
            'client_name' => 'nullable|string|max:255',
            'walk_in_name' => 'nullable|string|max:255',
            'payments' => 'required|array|min:1',
            'payments.*.method' => 'required|in:especes,carte,orange_money,wave',
            'payments.*.amount' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $discountAmount = (float) ($request->discount_amount ?? 0);
        $items = $request->items;
        $payments = $request->payments;

        $subtotal = collect($items)->sum(fn ($item) => (float) $item['unit_price'] * (int) $item['quantity']);
        $totalAmount = max(0, $subtotal - $discountAmount);
        $paymentsSum = collect($payments)->sum(fn ($p) => (float) $p['amount']);

        if (round($paymentsSum, 2) !== round($totalAmount, 2)) {
            return response()->json([
                'success' => false,
                'message' => 'La somme des paiements doit égaler le total dû',
                'errors' => [
                    'payments' => [
                        'Total dû : ' . number_format($totalAmount, 2, '.', '') .
                        ', reçu : ' . number_format($paymentsSum, 2, '.', ''),
                    ],
                ],
            ], 422);
        }

        $stockErrors = $this->validateStock($items);
        if (!empty($stockErrors)) {
            return response()->json([
                'success' => false,
                'message' => 'Stock insuffisant',
                'errors' => ['stock' => $stockErrors],
            ], 422);
        }

        $clientName = $request->client_name ?: $request->walk_in_name;
        $clientPhone = $request->client_phone ? trim($request->client_phone) : null;

        if ($clientPhone && !$request->client_id && !trim((string) $clientName)) {
            return response()->json([
                'success' => false,
                'message' => 'Nom requis pour enregistrer un nouveau client',
                'errors' => [
                    'client_name' => ['Saisissez le nom du client pour ce numéro inconnu'],
                ],
            ], 422);
        }

        try {
            DB::beginTransaction();

            $clientId = PosClientResolver::resolveForSale(
                $request->client_id,
                $clientPhone,
                $clientName
            );

            $order = Order::create([
                'client_id' => $clientId,
                'total_amount' => $totalAmount,
                'status' => 'disponible',
                'channel' => 'boutique',
                'cashier_id' => $request->user()->id,
                'cash_session_id' => $session->id,
                'amount_received' => $paymentsSum,
                'discount_amount' => $discountAmount,
                'discount_reason' => $request->discount_reason,
                'walk_in_name' => $clientId ? null : ($clientName ?: null),
            ]);

            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $quantity,
                ]);

                $this->decrementVariantStock($item['product_variant_id'] ?? null, $quantity);
            }

            foreach ($payments as $payment) {
                OrderPayment::create([
                    'order_id' => $order->id,
                    'method' => $payment['method'],
                    'amount' => $payment['amount'],
                ]);
            }

            DB::commit();

            $order->load(['items.product', 'items.variant', 'client', 'payments', 'cashier']);

            return response()->json([
                'success' => true,
                'message' => 'Vente enregistrée',
                'data' => $this->formatOrder($order),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la vente',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function today(Request $request): JsonResponse
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

        $orders = Order::query()
            ->where('cash_session_id', $session->id)
            ->where('channel', 'boutique')
            ->with(['items.product', 'items.variant', 'client', 'payments', 'cashier'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($order) => $this->formatOrder($order));

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul un administrateur peut annuler une vente',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string|max:1000',
        ], [
            'cancellation_reason.required' => 'Le motif d\'annulation est obligatoire',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::with(['items.variant'])->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable',
            ], 404);
        }

        if ($order->channel !== 'boutique') {
            return response()->json([
                'success' => false,
                'message' => 'Seules les ventes boutique peuvent être annulées depuis la caisse',
            ], 422);
        }

        if ($order->status === 'annulée') {
            return response()->json([
                'success' => false,
                'message' => 'Cette vente est déjà annulée',
            ], 422);
        }

        try {
            DB::beginTransaction();

            foreach ($order->items as $item) {
                $this->restoreVariantStock($item->product_variant_id, $item->quantity);
            }

            $order->update([
                'status' => 'annulée',
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $request->cancellation_reason,
                'cancelled_at' => now(),
            ]);

            DB::commit();

            $order->load(['items.product', 'items.variant', 'client', 'payments', 'cashier']);

            return response()->json([
                'success' => true,
                'message' => 'Vente annulée — stock remis à jour',
                'data' => $this->formatOrder($order),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function validateStock(array $items): array
    {
        $errors = [];

        foreach ($items as $index => $item) {
            if (empty($item['product_variant_id'])) {
                $product = Product::find($item['product_id']);
                if (!$product || !$product->is_active) {
                    $errors[] = "Article #{$index} : produit indisponible";
                }
                continue;
            }

            $variant = ProductVariant::with('product')->find($item['product_variant_id']);
            if (!$variant || !$variant->is_active || !$variant->product?->is_active) {
                $errors[] = "Article #{$index} : produit indisponible";
                continue;
            }

            if (!is_null($variant->stock_quantity) && $variant->stock_quantity > 0) {
                if ($variant->stock_quantity < (int) $item['quantity']) {
                    $label = $variant->product->name . ' — ' . $variant->name;
                    $errors[] = "{$label} : stock insuffisant (disponible : {$variant->stock_quantity})";
                }
            }
        }

        return $errors;
    }

    private function decrementVariantStock(?int $variantId, int $quantity): void
    {
        if (!$variantId) {
            return;
        }

        $variant = ProductVariant::lockForUpdate()->find($variantId);
        if ($variant && !is_null($variant->stock_quantity) && $variant->stock_quantity > 0) {
            $variant->increment('stock_quantity', -$quantity);
        }
    }

    private function restoreVariantStock(?int $variantId, int $quantity): void
    {
        if (!$variantId) {
            return;
        }

        $variant = ProductVariant::lockForUpdate()->find($variantId);
        if ($variant && !is_null($variant->stock_quantity) && $variant->stock_quantity > 0) {
            $variant->increment('stock_quantity', $quantity);
        }
    }
}
