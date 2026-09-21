<?php

namespace App\Services;

use App\Authorization\Permissions;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\StockPreorder;
use App\Models\User;

final class StaffAlertService
{
    /**
     * @return array<string, mixed>
     */
    public function present(User $actor): array
    {
        $items = [];

        if ($actor->hasPermissionTo(Permissions::ORDERS_VIEW)) {
            $count = $this->toValidateCount();
            $items[] = [
                'key' => 'to_validate',
                'label' => 'Commandes à valider',
                'count' => $count,
                'href' => '/admin/orders',
            ];
        }

        if ($actor->hasPermissionTo(Permissions::STOCK_VIEW_STATUS)) {
            $items[] = [
                'key' => 'low_stock',
                'label' => 'Stock faible ou rupture',
                'count' => $this->lowStockCount(),
                'href' => '/admin/stock',
            ];
            $items[] = [
                'key' => 'preorders',
                'label' => 'Précommandes à honorer',
                'count' => $this->preorderCount(),
                'href' => '/admin/stock',
            ];
        }

        return [
            'total' => collect($items)->sum('count'),
            'items' => $items,
        ];
    }

    public function toValidateCount(): int
    {
        return Order::query()
            ->where('status', 'en_attente')
            ->where(function ($query) {
                $query->whereNull('channel')->orWhere('channel', '!=', 'boutique');
            })
            ->whereRaw('(select coalesce(sum(amount), 0) from order_payments where order_payments.order_id = orders.id) < orders.total_amount')
            ->count();
    }

    public function lowStockCount(): int
    {
        $threshold = max(0, (int) ShopSetting::current()->low_stock_threshold);

        return ProductVariant::query()
            ->where('is_active', true)
            ->whereNotNull('stock_quantity')
            ->whereRaw('(stock_quantity - coalesce(reserved_quantity, 0)) <= ?', [$threshold])
            ->count();
    }

    public function preorderCount(): int
    {
        return (int) StockPreorder::query()
            ->where('status', StockPreorder::WAITING)
            ->where('quantity', '>', 0)
            ->sum('quantity');
    }
}
