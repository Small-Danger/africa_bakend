<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderPaymentService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AdminOrderPaymentController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo(Permissions::ORDERS_RECORD_PAYMENT)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n’avez pas le droit d’enregistrer un paiement',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'method' => 'required|string|in:'.implode(',', \App\Models\ShopSetting::paymentMethodKeys()),
            'amount' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500',
        ], [
            'method.required' => 'Indiquez le mode de paiement',
            'method.in' => 'Mode de paiement invalide',
            'amount.required' => 'Indiquez le montant reçu',
            'amount.min' => 'Le montant doit être au moins 1 FCFA',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::query()->find($id);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => 'Commande introuvable',
            ], 404);
        }

        try {
            $payment = app(OrderPaymentService::class)->record(
                $order,
                $request->user(),
                (string) $validator->validated()['method'],
                (int) $validator->validated()['amount'],
                isset($validator->validated()['reference'])
                    ? (trim((string) $validator->validated()['reference']) ?: null)
                    : null,
                isset($validator->validated()['note'])
                    ? (trim((string) $validator->validated()['note']) ?: null)
                    : null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $fresh = $order->fresh(['client', 'items.product', 'items.variant', 'reservations', 'preorders', 'payments.recordedBy']);
        $payments = app(OrderPaymentService::class)->present($fresh);

        return response()->json([
            'success' => true,
            'message' => $payments['payment_status'] === OrderPaymentService::STATUS_PAID
                ? 'Paiement enregistré, la commande est soldée'
                : 'Paiement enregistré',
            'data' => [
                'payment' => collect($payments['payments'])->firstWhere('id', $payment->id),
                'order' => [
                    'id' => $fresh->id,
                    'order_number' => 'CMD-'.str_pad((string) $fresh->id, 6, '0', STR_PAD_LEFT),
                    'status' => $fresh->status,
                    'total_amount' => $fresh->total_amount,
                    'client' => [
                        'id' => $fresh->client?->id,
                        'name' => $fresh->client?->name ?? $fresh->walk_in_name ?? 'Client',
                        'whatsapp_phone' => $fresh->client?->whatsapp_phone ?? $fresh->walk_in_phone,
                    ],
                    'reservation' => app(StockService::class)->presentReservation($fresh),
                    'preorder' => app(StockService::class)->presentPreorder($fresh),
                    ...$payments,
                ],
            ],
        ], 201);
    }
}
