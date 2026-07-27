<?php

namespace App\Http\Controllers\Api\Pos;

use App\Models\CashSession;
use App\Models\Order;

trait FormatsPosOrders
{
    protected function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => 'POS-' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'status' => $order->status,
            'channel' => $order->channel,
            'total_amount' => $order->total_amount,
            'discount_amount' => $order->discount_amount,
            'discount_reason' => $order->discount_reason,
            'amount_received' => $order->amount_received,
            'walk_in_name' => $order->walk_in_name,
            'cancellation_reason' => $order->cancellation_reason,
            'cancelled_at' => $order->cancelled_at,
            'client' => $order->client ? [
                'id' => $order->client->id,
                'name' => $order->client->name,
                'phone' => $order->client->phone ?? $order->client->whatsapp_phone,
            ] : null,
            'cashier' => $order->cashier ? [
                'id' => $order->cashier->id,
                'name' => $order->cashier->name,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product?->name,
                'variant_name' => $item->variant?->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ]),
            'payments' => $order->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'method' => $payment->method,
                'amount' => $payment->amount,
            ]),
            'created_at' => $order->created_at,
        ];
    }

    protected function calculateClosingExpected(CashSession $session): float
    {
        $cashSales = $session->orders()
            ->where('channel', 'boutique')
            ->where('status', '!=', 'annulée')
            ->with('payments')
            ->get()
            ->flatMap(fn ($order) => $order->payments)
            ->where('method', 'especes')
            ->sum('amount');

        $entrees = (float) $session->movements()->where('type', 'entree')->sum('amount');
        $sorties = (float) $session->movements()->where('type', 'sortie')->sum('amount');

        return (float) $session->opening_amount + (float) $cashSales + $entrees - $sorties;
    }
}
