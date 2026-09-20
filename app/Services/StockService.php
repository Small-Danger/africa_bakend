<?php

namespace App\Services;

use App\Authorization\Permissions;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\StockMovement;
use App\Models\StockPreorder;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\StockReservation;
use App\Models\User;
use App\Stock\StockState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StockService
{
    /**
     * @return array{
     *     on_hand: int,
     *     reserved: int,
     *     available: int,
     *     needs_inventory: bool,
     *     unlimited_legacy: bool,
     *     preorder_allowed: bool,
     *     preorder_delay_days: int,
     *     low_stock_threshold: int,
     *     state: string,
     *     label: string
     * }
     */
    public function snapshot(ProductVariant $variant): array
    {
        $variant->loadMissing('product');
        $settings = ShopSetting::current();
        $unlimitedLegacy = $variant->stock_quantity === null;
        $onHand = $unlimitedLegacy ? 0 : max(0, (int) $variant->stock_quantity);
        $reserved = max(0, (int) ($variant->reserved_quantity ?? 0));
        $available = $unlimitedLegacy ? PHP_INT_MAX : max(0, $onHand - $reserved);
        $preorderAllowed = (bool) ($variant->product?->preorder_allowed ?? true);
        $delay = (int) $settings->preorder_delay_days;
        $threshold = (int) $settings->low_stock_threshold;

        if ($unlimitedLegacy) {
            if ($preorderAllowed) {
                $state = StockState::SUR_COMMANDE;
                $label = 'Sur commande';
            } else {
                $state = StockState::RUPTURE;
                $label = 'Indisponible';
            }
        } elseif ($available > 0) {
            $state = StockState::EN_STOCK;
            $label = $available <= $threshold ? 'Plus que '.$available : 'En stock';
        } elseif ($preorderAllowed) {
            $state = StockState::SUR_COMMANDE;
            $label = 'Sur commande';
        } else {
            $state = StockState::RUPTURE;
            $label = 'Indisponible';
        }

        return [
            'on_hand' => $unlimitedLegacy ? 0 : $onHand,
            'reserved' => $reserved,
            'available' => $unlimitedLegacy ? 0 : $available,
            'needs_inventory' => $unlimitedLegacy,
            'unlimited_legacy' => $unlimitedLegacy,
            'preorder_allowed' => $preorderAllowed,
            'preorder_delay_days' => $delay,
            'low_stock_threshold' => $threshold,
            'state' => $state,
            'label' => $label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ProductVariant $variant, ?User $viewer = null, bool $public = false): array
    {
        $snap = $this->snapshot($variant);
        $showQty = ! $public && $viewer && $viewer->hasPermissionTo(Permissions::STOCK_VIEW_QUANTITIES);

        $payload = [
            'stock_status' => $snap['state'],
            'stock_label' => $snap['label'],
            'preorder_allowed' => $snap['preorder_allowed'],
            'preorder_delay_days' => $snap['preorder_delay_days'],
            'needs_inventory' => $snap['needs_inventory'],
            'is_available' => $snap['state'] !== StockState::RUPTURE,
            'can_sell_from_stock' => $snap['state'] === StockState::EN_STOCK && ! $snap['unlimited_legacy']
                ? $snap['available'] > 0
                : $snap['unlimited_legacy'] || $snap['state'] === StockState::EN_STOCK,
        ];

        if ($showQty) {
            $payload['stock_quantity'] = $snap['unlimited_legacy'] ? null : $snap['available'];
            $payload['stock_on_hand'] = $snap['unlimited_legacy'] ? null : $snap['on_hand'];
            $payload['stock_reserved'] = $snap['reserved'];
        } else {
            $payload['stock_quantity'] = null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function decorate(array $base, ProductVariant $variant, ?User $viewer = null, bool $public = false): array
    {
        return array_merge($base, $this->present($variant, $viewer, $public));
    }

    /**
     * État agrégé pour les cartes catalogue : en stock > sur commande > rupture.
     *
     * @return array{
     *     stock_status: string,
     *     stock_label: string,
     *     needs_inventory: bool,
     *     is_available: bool
     * }
     */
    public function presentProduct(Product $product): array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->where('is_active', true)->get();

        if ($variants->isEmpty()) {
            return [
                'stock_status' => StockState::SUR_COMMANDE,
                'stock_label' => 'Sur commande',
                'needs_inventory' => true,
                'is_available' => true,
            ];
        }

        $snaps = $variants->map(fn (ProductVariant $variant) => $this->snapshot($variant));

        if ($snaps->contains(fn (array $snap) => $snap['state'] === StockState::EN_STOCK)) {
            return [
                'stock_status' => StockState::EN_STOCK,
                'stock_label' => 'En stock',
                'needs_inventory' => false,
                'is_available' => true,
            ];
        }

        if ($snaps->contains(fn (array $snap) => $snap['state'] === StockState::SUR_COMMANDE)) {
            return [
                'stock_status' => StockState::SUR_COMMANDE,
                'stock_label' => 'Sur commande',
                'needs_inventory' => false,
                'is_available' => true,
            ];
        }

        return [
            'stock_status' => StockState::RUPTURE,
            'stock_label' => 'Indisponible',
            'needs_inventory' => false,
            'is_available' => false,
        ];
    }

    /**
     * Ligne d'inventaire pour l'écran admin Stock.
     *
     * @return array<string, mixed>
     */
    public function presentInventory(ProductVariant $variant, User $viewer): array
    {
        $snap = $this->snapshot($variant);
        $showQty = $viewer->hasPermissionTo(Permissions::STOCK_VIEW_QUANTITIES);
        $isLow = ! $snap['unlimited_legacy']
            && $snap['state'] === StockState::EN_STOCK
            && $snap['available'] > 0
            && $snap['available'] <= $snap['low_stock_threshold'];

        $label = $showQty
            ? ($snap['needs_inventory']
                ? ($snap['state'] === StockState::SUR_COMMANDE ? 'Sur commande' : $snap['label'])
                : $snap['label'])
            : match (true) {
                $snap['state'] === StockState::SUR_COMMANDE => 'Sur commande',
                $snap['state'] === StockState::RUPTURE => 'Indisponible',
                $isLow => 'Stock faible',
                default => 'En stock',
            };

        $product = $variant->relationLoaded('product') ? $variant->product : $variant->product()->first();

        $payload = [
            'id' => $variant->id,
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'category_name' => $product?->category?->name,
            'is_active' => (bool) $variant->is_active,
            'stock_status' => $snap['state'],
            'stock_label' => $label,
            'needs_inventory' => $snap['needs_inventory'],
            'is_low' => $isLow,
            'preorder_allowed' => $snap['preorder_allowed'],
        ];

        if ($showQty) {
            $payload['stock_quantity'] = $snap['unlimited_legacy'] ? null : $snap['available'];
            $payload['stock_on_hand'] = $snap['unlimited_legacy'] ? null : $snap['on_hand'];
            $payload['stock_reserved'] = $snap['reserved'];
        }

        return $payload;
    }

    public function recordOpening(ProductVariant $variant, ?User $actor = null): void
    {
        if ($variant->stock_quantity === null) {
            return;
        }

        $this->writeMovement(
            $variant,
            (int) $variant->stock_quantity,
            'inventaire_initial',
            'admin',
            $actor,
            null,
            'Stock initial'
        );
    }

    public function canFulfillFromStock(ProductVariant $variant, int $quantity): bool
    {
        $snap = $this->snapshot($variant);
        if ($snap['unlimited_legacy']) {
            return true;
        }
        if ($snap['state'] === StockState::RUPTURE) {
            return false;
        }
        if ($snap['available'] >= $quantity) {
            return true;
        }

        return $snap['preorder_allowed'];
    }

    public function reserveForOrder(Order $order, ?User $actor = null, string $channel = 'site'): void
    {
        $this->expireOverdueReservations();

        DB::transaction(function () use ($order, $actor, $channel) {
            $lockedOrder = Order::query()->lockForUpdate()->with('items.variant.product')->findOrFail($order->id);
            if ($lockedOrder->reservations()->exists()) {
                return;
            }

            $hours = max(1, (int) ShopSetting::current()->unpaid_expiry_hours);
            $expiresAt = now()->addHours($hours);
            $units = 0;
            $queued = 0;

            foreach ($lockedOrder->items as $item) {
                $variant = $item->variant;
                if (! $variant) {
                    continue;
                }

                $requested = (int) $item->quantity;
                if ($requested < 1) {
                    continue;
                }

                $locked = ProductVariant::query()->lockForUpdate()->find($variant->id);
                if (! $locked) {
                    throw new InvalidArgumentException('Variante introuvable');
                }

                $snap = $this->snapshot($locked);
                if ($snap['state'] === StockState::RUPTURE) {
                    throw new InvalidArgumentException($this->variantLabel($locked).' est en rupture');
                }

                $take = min($snap['available'], $requested);
                $wait = $requested - $take;

                if ($wait > 0 && ! $snap['preorder_allowed']) {
                    throw new InvalidArgumentException(
                        $this->variantLabel($locked).' : stock insuffisant et précommande non autorisée'
                    );
                }

                if ($take > 0) {
                    $this->addReservationUnits($lockedOrder, $locked, $take, $expiresAt, $actor, $channel);
                    $units += $take;
                }

                if ($wait > 0) {
                    StockPreorder::query()->create([
                        'order_id' => $lockedOrder->id,
                        'product_variant_id' => $locked->id,
                        'quantity' => $wait,
                        'original_quantity' => $wait,
                        'status' => StockPreorder::WAITING,
                        'expires_at' => $expiresAt,
                    ]);
                    $queued += $wait;

                    $this->writeMovement(
                        $locked,
                        0,
                        'precommande',
                        $channel,
                        $actor,
                        $lockedOrder,
                        'Mise en file d’attente',
                        ['queued' => $wait, 'reserved' => $take, 'requested' => $requested],
                    );
                }
            }

            if ($units > 0) {
                ActivityLogger::record(
                    $actor,
                    'stock.reserved',
                    'Réservation de '.$units.' pièce(s) pour la commande #'.$lockedOrder->id,
                    $lockedOrder,
                    ['units' => $units, 'expires_at' => $expiresAt->toIso8601String()],
                    $channel === 'pos' ? 'pos' : 'admin',
                );
            }

            if ($queued > 0) {
                ActivityLogger::record(
                    $actor,
                    'stock.preorder_queued',
                    'Précommande de '.$queued.' pièce(s) en file pour la commande #'.$lockedOrder->id,
                    $lockedOrder,
                    ['units' => $queued, 'expires_at' => $expiresAt->toIso8601String()],
                    $channel === 'pos' ? 'pos' : 'admin',
                );
            }
        });
    }

    public function confirmReservationsFor(Order $order, ?User $actor = null, string $channel = 'site'): void
    {
        DB::transaction(function () use ($order, $actor, $channel) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $rows = StockReservation::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', StockReservation::ACTIVE)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $locked = ProductVariant::query()->lockForUpdate()->find($row->product_variant_id);
                if (! $locked || $locked->stock_quantity === null) {
                    $row->status = StockReservation::CONFIRMED;
                    $row->closed_at = now();
                    $row->save();
                    continue;
                }

                $qty = (int) $row->quantity;
                $onHand = max(0, (int) $locked->stock_quantity);
                $take = min($onHand, $qty);
                $locked->stock_quantity = $onHand - $take;
                $locked->reserved_quantity = max(0, (int) ($locked->reserved_quantity ?? 0) - $qty);
                $locked->save();

                $row->status = StockReservation::CONFIRMED;
                $row->closed_at = now();
                $row->save();

                $this->writeMovement(
                    $locked,
                    -$take,
                    $channel === 'pos' ? 'vente_pos' : 'vente_site',
                    $channel,
                    $actor,
                    $lockedOrder,
                    'Confirmation de réservation',
                    ['reservation_id' => $row->id, 'reserved' => $qty],
                );
            }
        });
    }

    public function releaseReservationsFor(
        Order $order,
        string $status = StockReservation::RELEASED,
        string $reason = 'Réservation libérée',
        ?User $actor = null,
        string $channel = 'site',
    ): void {
        DB::transaction(function () use ($order, $status, $reason, $actor, $channel) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $rows = StockReservation::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', StockReservation::ACTIVE)
                ->lockForUpdate()
                ->get();

            $units = 0;
            foreach ($rows as $row) {
                $locked = ProductVariant::query()->lockForUpdate()->find($row->product_variant_id);
                $qty = (int) $row->quantity;
                $units += $qty;

                if ($locked) {
                    $locked->reserved_quantity = max(0, (int) ($locked->reserved_quantity ?? 0) - $qty);
                    $locked->save();
                    $this->writeMovement(
                        $locked,
                        0,
                        $status === StockReservation::EXPIRED ? 'reservation_expiree' : 'reservation_liberee',
                        $channel,
                        $actor,
                        $lockedOrder,
                        $reason,
                        ['reservation_id' => $row->id, 'released' => $qty],
                    );
                }

                $row->status = $status;
                $row->closed_at = now();
                $row->save();
            }

            $queued = $this->cancelPreordersFor($lockedOrder, $actor, $channel);

            if ($units > 0) {
                ActivityLogger::record(
                    $actor,
                    $status === StockReservation::EXPIRED ? 'stock.reservation_expired' : 'stock.reservation_released',
                    $reason.' : '.$units.' pièce(s) pour la commande #'.$lockedOrder->id,
                    $lockedOrder,
                    ['units' => $units],
                    $channel === 'pos' ? 'pos' : 'admin',
                );
            }

            if ($queued > 0 && $status !== StockReservation::EXPIRED) {
                ActivityLogger::record(
                    $actor,
                    'stock.preorder_cancelled',
                    'Précommande retirée de la file : '.$queued.' pièce(s) pour la commande #'.$lockedOrder->id,
                    $lockedOrder,
                    ['units' => $queued],
                    $channel === 'pos' ? 'pos' : 'admin',
                );
            }
        });
    }

    public function syncOrderHold(Order $order, string $newStatus, ?User $actor = null, string $channel = 'site'): void
    {
        if (in_array($newStatus, Order::closedStatuses(), true)) {
            $variantIds = $this->orderVariantIds($order);
            $this->releaseReservationsFor(
                $order,
                $newStatus === 'expirée' ? StockReservation::EXPIRED : StockReservation::RELEASED,
                $newStatus === 'expirée' ? 'Commande expirée' : 'Annulation de commande',
                $actor,
                $channel,
            );
            $this->reverseSalesFor($order, $channel, $actor);
            $this->fulfillAfterStockFreed($variantIds, $actor);

            return;
        }

        if (in_array($newStatus, ['prête', 'en_cours', 'disponible'], true)) {
            $this->confirmReservationsFor($order, $actor, $channel);

            return;
        }

        if ($newStatus === 'acceptée') {
            $hours = max(1, (int) ShopSetting::current()->unpaid_expiry_hours);
            $expiresAt = now()->addHours($hours);
            StockReservation::query()
                ->where('order_id', $order->id)
                ->where('status', StockReservation::ACTIVE)
                ->update(['expires_at' => $expiresAt]);
            StockPreorder::query()
                ->where('order_id', $order->id)
                ->where('status', StockPreorder::WAITING)
                ->update(['expires_at' => $expiresAt]);
        }
    }

    public function expireOverdueReservations(): int
    {
        $hours = max(1, (int) ShopSetting::current()->unpaid_expiry_hours);
        $cutoff = now()->subHours($hours);

        $holdIds = StockReservation::query()
            ->where('status', StockReservation::ACTIVE)
            ->where('expires_at', '<=', now())
            ->pluck('order_id')
            ->merge(
                StockPreorder::query()
                    ->where('status', StockPreorder::WAITING)
                    ->where('expires_at', '<=', now())
                    ->pluck('order_id')
            );

        $agedIds = Order::query()
            ->whereIn('status', ['en_attente', 'acceptée'])
            ->where(function ($query) {
                $query->whereNull('channel')->orWhere('channel', '!=', 'boutique');
            })
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('payments')
            ->pluck('id');

        $orderIds = $holdIds->merge($agedIds)->unique()->filter()->values()->all();
        $freedVariantIds = [];
        $count = 0;

        foreach ($orderIds as $orderId) {
            DB::transaction(function () use ($orderId, $cutoff, &$count, &$freedVariantIds) {
                $order = Order::query()->lockForUpdate()->find($orderId);
                if (! $order || ! in_array($order->status, ['en_attente', 'acceptée'], true)) {
                    return;
                }

                if (($order->channel ?? 'en_ligne') === 'boutique') {
                    return;
                }

                $order->load('payments');
                if ((int) round((float) $order->payments->sum('amount')) > 0) {
                    return;
                }

                $reservationDue = StockReservation::query()
                    ->where('order_id', $order->id)
                    ->where('status', StockReservation::ACTIVE)
                    ->where('expires_at', '<=', now())
                    ->exists();

                $preorderDue = StockPreorder::query()
                    ->where('order_id', $order->id)
                    ->where('status', StockPreorder::WAITING)
                    ->where('expires_at', '<=', now())
                    ->exists();

                $hasActiveHold = StockReservation::query()
                    ->where('order_id', $order->id)
                    ->where('status', StockReservation::ACTIVE)
                    ->exists()
                    || StockPreorder::query()
                        ->where('order_id', $order->id)
                        ->where('status', StockPreorder::WAITING)
                        ->exists();

                $aged = $order->created_at && $order->created_at->lte($cutoff);

                if (! $reservationDue && ! $preorderDue && ($hasActiveHold || ! $aged)) {
                    return;
                }

                $freedVariantIds = array_merge($freedVariantIds, $this->orderVariantIds($order));

                $this->releaseReservationsFor(
                    $order,
                    StockReservation::EXPIRED,
                    'Commande non payée expirée',
                    null,
                    'site',
                );

                $order->status = 'expirée';
                $order->cancellation_reason = $preorderDue && ! $reservationDue
                    ? 'Précommande expirée'
                    : ($reservationDue ? 'Réservation expirée' : 'Commande non payée expirée');
                $order->cancelled_at = now();
                $order->save();

                ActivityLogger::record(
                    null,
                    'order.expired',
                    'Commande #'.$order->id.' expirée : aucun paiement dans le délai',
                    $order,
                    ['reason' => $order->cancellation_reason],
                    'system',
                );

                $count++;
            });
        }

        $this->fulfillAfterStockFreed($freedVariantIds);

        return $count;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentReservation(Order $order): ?array
    {
        $order->loadMissing('reservations');
        $rows = $order->reservations;
        if ($rows->isEmpty()) {
            return null;
        }

        $active = $rows->where('status', StockReservation::ACTIVE);
        $latest = $rows->sortByDesc('id')->first();
        $status = $active->isNotEmpty() ? StockReservation::ACTIVE : (string) $latest?->status;
        $expires = $active->min('expires_at') ?? $rows->max('expires_at');

        return [
            'status' => $status,
            'expires_at' => $expires ? $expires->toIso8601String() : null,
            'units' => (int) ($active->isNotEmpty() ? $active->sum('quantity') : $rows->sum('quantity')),
            'label' => match ($status) {
                StockReservation::ACTIVE => 'Stock réservé',
                StockReservation::CONFIRMED => 'Stock débité',
                StockReservation::RELEASED => 'Réservation libérée',
                StockReservation::EXPIRED => 'Réservation expirée',
                default => $status,
            },
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentPreorder(Order $order): ?array
    {
        $order->loadMissing('preorders');
        $rows = $order->preorders;
        if ($rows->isEmpty()) {
            return null;
        }

        $waiting = $rows->where('status', StockPreorder::WAITING);
        $latest = $rows->sortByDesc('id')->first();
        $status = $waiting->isNotEmpty() ? StockPreorder::WAITING : (string) $latest?->status;

        return [
            'status' => $status,
            'units' => (int) $waiting->sum('quantity'),
            'label' => match ($status) {
                StockPreorder::WAITING => 'En file d’attente',
                StockPreorder::ALLOCATED => 'Précommande servie',
                StockPreorder::CANCELLED => 'Précommande annulée',
                default => $status,
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentQueueRow(StockPreorder $row, int $position): array
    {
        $row->loadMissing(['order.client', 'variant.product']);
        $order = $row->order;
        $variant = $row->variant;
        $product = $variant?->product;

        return [
            'id' => $row->id,
            'position' => $position,
            'order_id' => $row->order_id,
            'order_number' => 'CMD-'.str_pad((string) $row->order_id, 6, '0', STR_PAD_LEFT),
            'customer_name' => $order?->client?->name ?? $order?->walk_in_name ?? 'Client',
            'customer_phone' => $order?->client?->whatsapp_phone ?? $order?->walk_in_phone,
            'product_name' => $product?->name,
            'variant_name' => $variant?->name,
            'variant_id' => $row->product_variant_id,
            'quantity' => (int) $row->quantity,
            'original_quantity' => (int) $row->original_quantity,
            'status' => $row->status,
            'created_at' => optional($row->created_at)->toIso8601String(),
            'expires_at' => optional($row->expires_at)->toIso8601String(),
        ];
    }

    public function commitSale(
        ProductVariant $variant,
        int $quantity,
        string $channel,
        ?User $actor = null,
        ?Model $reference = null,
    ): void {
        if ($quantity < 1) {
            throw new InvalidArgumentException('La quantité doit être positive');
        }

        DB::transaction(function () use ($variant, $quantity, $channel, $actor, $reference) {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $snap = $this->snapshot($locked);

            if ($snap['state'] === StockState::RUPTURE) {
                throw new InvalidArgumentException('Cet article est en rupture');
            }

            $delta = 0;
            if (! $snap['unlimited_legacy'] && $snap['available'] > 0) {
                $delta = min($snap['available'], $quantity);
                $locked->stock_quantity = $snap['on_hand'] - $delta;
                $locked->save();
            }

            $this->writeMovement(
                $locked,
                -$delta,
                $delta > 0 ? ($channel === 'pos' ? 'vente_pos' : 'vente_site') : 'precommande',
                $channel,
                $actor,
                $reference,
                $delta === $quantity ? 'Vente' : 'Vente dont partie en précommande',
                ['requested' => $quantity, 'taken_from_stock' => $delta],
            );
        });
    }

    public function restock(
        ProductVariant $variant,
        int $quantity,
        string $channel,
        ?User $actor = null,
        ?Model $reference = null,
        string $reason = 'Retour en stock',
        string $type = 'annulation',
    ): void {
        if ($quantity < 1) {
            return;
        }

        DB::transaction(function () use ($variant, $quantity, $channel, $actor, $reference, $reason, $type) {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            if ($locked->stock_quantity === null) {
                return;
            }

            $locked->stock_quantity = max(0, (int) $locked->stock_quantity) + $quantity;
            $locked->save();

            $this->writeMovement($locked, $quantity, $type, $channel, $actor, $reference, $reason);
        });
    }

    public function reverseSalesFor(Model $reference, string $channel, ?User $actor = null): void
    {
        $alreadyReversed = StockMovement::query()
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->where('type', 'annulation')
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $movements = StockMovement::query()
            ->with('variant')
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->where('quantity', '<', 0)
            ->whereIn('type', ['vente_pos', 'vente_site', 'precommande'])
            ->get();

        foreach ($movements as $movement) {
            if (! $movement->variant) {
                continue;
            }

            $this->restock(
                $movement->variant,
                abs((int) $movement->quantity),
                $channel,
                $actor,
                $reference,
                'Annulation',
                'annulation',
            );
        }
    }

    public function adjust(
        ProductVariant $variant,
        int $newQuantity,
        ?User $actor = null,
        string $reason = 'Correction de stock',
    ): ProductVariant {
        if ($newQuantity < 0) {
            throw new InvalidArgumentException('La quantité ne peut pas être négative');
        }

        [$updated, $increased] = DB::transaction(function () use ($variant, $newQuantity, $actor, $reason) {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $before = $locked->stock_quantity;
            $from = $before === null ? null : (int) $before;
            $delta = $newQuantity - ($from ?? 0);
            $locked->stock_quantity = $newQuantity;
            $locked->save();

            $this->writeMovement(
                $locked,
                $delta,
                'correction_inventaire',
                'admin',
                $actor,
                null,
                $reason,
                ['from' => $from, 'to' => $newQuantity],
            );

            $fresh = $locked->fresh();

            ActivityLogger::record(
                $actor,
                'stock.adjusted',
                'Stock corrigé pour la variante '.$fresh->name,
                $fresh,
                ['from' => $from, 'to' => $newQuantity],
                'admin',
            );

            return [$fresh, $newQuantity > ($from ?? 0)];
        });

        if ($increased) {
            $this->fulfillAfterStockFreed([$variant->id], $actor);
        }

        return $updated;
    }

    /**
     * Réception d'un arrivage : on ajoute la quantité, on n'écrase jamais un compte.
     *
     * @param  array{
     *     items: list<array{variant_id:int, quantity:int, unit_cost?:int|null}>,
     *     note?: string|null,
     *     merchandise_cost?: int,
     *     shipping_cost?: int,
     *     received_at?: \DateTimeInterface|string|null
     * }  $payload
     */
    public function receiveReceipt(array $payload, User $actor): StockReceipt
    {
        $lines = $this->mergeReceiptLines($payload['items'] ?? []);

        if ($lines === []) {
            throw new InvalidArgumentException('Ajoutez au moins une variante avec une quantité reçue');
        }

        $receipt = DB::transaction(function () use ($payload, $actor, $lines) {
            $receipt = StockReceipt::query()->create([
                'user_id' => $actor->id,
                'received_at' => $payload['received_at'] ?? now(),
                'note' => isset($payload['note']) ? (trim((string) $payload['note']) ?: null) : null,
                'merchandise_cost' => max(0, (int) ($payload['merchandise_cost'] ?? 0)),
                'shipping_cost' => max(0, (int) ($payload['shipping_cost'] ?? 0)),
            ]);

            $units = 0;
            foreach ($lines as $variantId => $line) {
                $locked = ProductVariant::query()->lockForUpdate()->find($variantId);
                if (! $locked) {
                    throw new InvalidArgumentException('Variante introuvable');
                }

                $qty = (int) $line['quantity'];
                $before = $locked->stock_quantity;
                $from = $before === null ? 0 : (int) $before;
                $locked->stock_quantity = $from + $qty;
                $locked->save();
                $units += $qty;

                $item = StockReceiptItem::query()->create([
                    'stock_receipt_id' => $receipt->id,
                    'product_variant_id' => $locked->id,
                    'quantity' => $qty,
                    'unit_cost' => $line['unit_cost'] ?? null,
                ]);

                $this->writeMovement(
                    $locked,
                    $qty,
                    'reception',
                    'admin',
                    $actor,
                    $receipt,
                    $receipt->note ?: 'Réception de stock',
                    [
                        'from' => $before,
                        'to' => (int) $locked->stock_quantity,
                        'receipt_item_id' => $item->id,
                    ],
                );
            }

            $receipt->load(['items.variant.product', 'user']);

            ActivityLogger::record(
                $actor,
                'stock.received',
                'Réception de '.$units.' pièce(s) sur '.$receipt->items->count().' variante(s)',
                $receipt,
                [
                    'units' => $units,
                    'lines' => $receipt->items->count(),
                    'merchandise_cost' => $receipt->merchandise_cost,
                    'shipping_cost' => $receipt->shipping_cost,
                ],
                'admin',
            );

            return $receipt;
        });

        $this->fulfillAfterStockFreed(array_keys($lines), $actor);

        return $receipt->fresh(['items.variant.product', 'user']);
    }

    /**
     * Corrige un arrivage : le stock suit la différence, la fiche et le journal restent.
     *
     * @param  array{
     *     items: list<array{variant_id:int, quantity:int, unit_cost?:int|null}>,
     *     note?: string|null,
     *     merchandise_cost?: int,
     *     shipping_cost?: int,
     *     received_at?: \DateTimeInterface|string|null
     * }  $payload
     */
    public function updateReceipt(StockReceipt $receipt, array $payload, User $actor): StockReceipt
    {
        $lines = $this->mergeReceiptLines($payload['items'] ?? []);
        if ($lines === []) {
            throw new InvalidArgumentException('Ajoutez au moins une variante avec une quantité reçue');
        }

        $fresh = DB::transaction(function () use ($receipt, $payload, $actor, $lines) {
            $lockedReceipt = StockReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->assertReceiptActive($lockedReceipt);
            $lockedReceipt->load('items');

            $oldLines = [];
            foreach ($lockedReceipt->items as $item) {
                $oldLines[(int) $item->product_variant_id] = (int) $item->quantity;
            }

            $variantIds = array_values(array_unique(array_merge(array_keys($oldLines), array_keys($lines))));
            $variants = $this->lockVariantsById($variantIds);

            foreach ($variantIds as $variantId) {
                $delta = (int) ($lines[$variantId]['quantity'] ?? 0) - (int) ($oldLines[$variantId] ?? 0);
                if ($delta === 0) {
                    continue;
                }

                $this->applyQuantityDelta(
                    $variants[$variantId],
                    $delta,
                    $actor,
                    $lockedReceipt,
                    'reception_correction',
                    $delta > 0 ? 'Correction d’arrivage (ajout)' : 'Correction d’arrivage (retrait)',
                );
            }

            $existing = $lockedReceipt->items->keyBy(fn (StockReceiptItem $item) => (int) $item->product_variant_id);
            foreach ($existing as $variantId => $item) {
                if (! isset($lines[$variantId])) {
                    $item->delete();
                    continue;
                }
                $item->quantity = $lines[$variantId]['quantity'];
                if (array_key_exists('unit_cost', $lines[$variantId])) {
                    $item->unit_cost = $lines[$variantId]['unit_cost'];
                }
                $item->save();
            }
            foreach ($lines as $variantId => $line) {
                if ($existing->has($variantId)) {
                    continue;
                }
                StockReceiptItem::query()->create([
                    'stock_receipt_id' => $lockedReceipt->id,
                    'product_variant_id' => $variantId,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'] ?? null,
                ]);
            }

            if (array_key_exists('merchandise_cost', $payload)) {
                $lockedReceipt->merchandise_cost = max(0, (int) $payload['merchandise_cost']);
            }
            if (array_key_exists('shipping_cost', $payload)) {
                $lockedReceipt->shipping_cost = max(0, (int) $payload['shipping_cost']);
            }
            if (array_key_exists('note', $payload)) {
                $lockedReceipt->note = trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null;
            }
            if (array_key_exists('received_at', $payload) && $payload['received_at']) {
                $lockedReceipt->received_at = $payload['received_at'];
            }
            $lockedReceipt->save();

            $fresh = $lockedReceipt->fresh(['items.variant.product', 'user', 'cancelledBy']);
            $units = (int) $fresh->items->sum('quantity');

            ActivityLogger::record(
                $actor,
                'stock.receipt_updated',
                'Arrivage modifié : '.$units.' pièce(s) sur '.$fresh->items->count().' variante(s)',
                $fresh,
                [
                    'units' => $units,
                    'merchandise_cost' => $fresh->merchandise_cost,
                    'shipping_cost' => $fresh->shipping_cost,
                ],
                'admin',
            );

            return $fresh;
        });

        $this->fulfillAfterStockFreed(array_keys($lines), $actor);

        return $fresh->fresh(['items.variant.product', 'user', 'cancelledBy']);
    }

    public function cancelReceipt(StockReceipt $receipt, User $actor, string $confirmation, ?string $reason = null): StockReceipt
    {
        if ($confirmation !== 'DELETE') {
            throw new InvalidArgumentException('Tapez DELETE pour confirmer l’annulation');
        }

        return DB::transaction(function () use ($receipt, $actor, $reason) {
            $lockedReceipt = StockReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $this->assertReceiptActive($lockedReceipt);
            $lockedReceipt->load('items');

            if ($lockedReceipt->items->isEmpty()) {
                throw new InvalidArgumentException('Cet arrivage n’a aucune ligne à annuler');
            }

            $variantIds = $lockedReceipt->items->pluck('product_variant_id')->map(fn ($id) => (int) $id)->all();
            $variants = $this->lockVariantsById($variantIds);

            foreach ($lockedReceipt->items as $item) {
                $this->applyQuantityDelta(
                    $variants[(int) $item->product_variant_id],
                    -1 * (int) $item->quantity,
                    $actor,
                    $lockedReceipt,
                    'reception_annulee',
                    $reason ?: 'Annulation d’arrivage',
                    ['receipt_item_id' => $item->id],
                );
            }

            $lockedReceipt->cancelled_at = now();
            $lockedReceipt->cancelled_by = $actor->id;
            $lockedReceipt->cancel_reason = $reason ? (trim($reason) ?: null) : null;
            $lockedReceipt->save();

            $fresh = $lockedReceipt->fresh(['items.variant.product', 'user', 'cancelledBy']);

            ActivityLogger::record(
                $actor,
                'stock.receipt_cancelled',
                'Arrivage annulé : le stock a été retiré, l’historique est conservé',
                $fresh,
                [
                    'units' => (int) $fresh->items->sum('quantity'),
                    'merchandise_cost' => $fresh->merchandise_cost,
                    'shipping_cost' => $fresh->shipping_cost,
                ],
                'admin',
            );

            return $fresh;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function presentReceipt(StockReceipt $receipt, User $viewer): array
    {
        $showMoney = $viewer->hasPermissionTo(Permissions::FINANCE_VIEW);
        $units = (int) $receipt->items->sum('quantity');
        $cancelled = $receipt->isCancelled();

        $payload = [
            'id' => $receipt->id,
            'received_at' => optional($receipt->received_at)->toIso8601String(),
            'note' => $receipt->note,
            'units' => $units,
            'lines_count' => $receipt->items->count(),
            'actor_name' => $receipt->user?->name,
            'cancelled' => $cancelled,
            'cancelled_at' => optional($receipt->cancelled_at)->toIso8601String(),
            'cancelled_by_name' => $receipt->cancelledBy?->name,
            'items' => $receipt->items->map(function (StockReceiptItem $item) {
                $variant = $item->variant;
                $product = $variant?->product;

                return [
                    'id' => $item->id,
                    'variant_id' => $item->product_variant_id,
                    'product_name' => $product?->name,
                    'variant_name' => $variant?->name,
                    'quantity' => $item->quantity,
                ];
            })->values()->all(),
        ];

        if ($showMoney) {
            $payload['merchandise_cost'] = (int) $receipt->merchandise_cost;
            $payload['shipping_cost'] = (int) $receipt->shipping_cost;
            $payload['invested'] = $cancelled ? 0 : $receipt->invested();
        }

        return $payload;
    }

    /**
     * @param  list<array{variant_id?:int, quantity?:int, unit_cost?:int|null}>  $items
     * @return array<int, array{quantity: int, unit_cost?: int}>
     */
    private function mergeReceiptLines(array $items): array
    {
        $lines = [];
        foreach ($items as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($variantId < 1 || $quantity < 1) {
                continue;
            }
            $lines[$variantId]['quantity'] = ($lines[$variantId]['quantity'] ?? 0) + $quantity;
            if (array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null && $line['unit_cost'] !== '') {
                $lines[$variantId]['unit_cost'] = (int) $line['unit_cost'];
            }
        }

        return $lines;
    }

    private function assertReceiptActive(StockReceipt $receipt): void
    {
        if ($receipt->isCancelled()) {
            throw new InvalidArgumentException('Cet arrivage a déjà été annulé');
        }
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection<int, ProductVariant>
     */
    private function lockVariantsById(array $ids): \Illuminate\Support\Collection
    {
        $unique = array_values(array_unique(array_map('intval', $ids)));
        sort($unique);

        $locked = collect();
        foreach ($unique as $id) {
            $variant = ProductVariant::query()->lockForUpdate()->find($id);
            if (! $variant) {
                throw new InvalidArgumentException('Variante introuvable');
            }
            $locked[$id] = $variant;
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function applyQuantityDelta(
        ProductVariant $locked,
        int $delta,
        User $actor,
        StockReceipt $receipt,
        string $type,
        string $reason,
        array $properties = [],
    ): void {
        if ($delta === 0) {
            return;
        }

        $before = $locked->stock_quantity;
        $onHand = $before === null ? 0 : max(0, (int) $before);
        $reserved = max(0, (int) ($locked->reserved_quantity ?? 0));

        if ($delta < 0) {
            $available = max(0, $onHand - $reserved);
            if (abs($delta) > $available) {
                throw new InvalidArgumentException(
                    'Impossible de retirer '.$this->variantLabel($locked).' : des pièces ont déjà été vendues ou réservées'
                );
            }
        }

        $locked->stock_quantity = $onHand + $delta;
        $locked->save();

        $this->writeMovement(
            $locked,
            $delta,
            $type,
            'admin',
            $actor,
            $receipt,
            $reason,
            array_merge([
                'from' => $before,
                'to' => (int) $locked->stock_quantity,
            ], $properties),
        );
    }

    /**
     * @param  list<int>  $variantIds
     */
    public function fulfillAfterStockFreed(array $variantIds, ?User $actor = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $variantIds))));
        if ($ids === []) {
            return;
        }

        $readyIds = [];
        DB::transaction(function () use ($ids, $actor, &$readyIds) {
            $variants = $this->lockVariantsById($ids);
            foreach ($variants as $variant) {
                $readyIds = array_merge($readyIds, $this->allocateWaitingPreorders($variant, $actor));
            }
        });

        $this->promoteAllocatedOrders(array_values(array_unique($readyIds)), $actor);
    }

    /**
     * @return list<int>
     */
    private function allocateWaitingPreorders(ProductVariant $locked, ?User $actor): array
    {
        if ($locked->stock_quantity === null) {
            return [];
        }

        $leftover = $this->snapshot($locked)['available'];
        if ($leftover < 1) {
            return [];
        }

        $rows = StockPreorder::query()
            ->where('product_variant_id', $locked->id)
            ->where('status', StockPreorder::WAITING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $readyOrderIds = [];
        $units = 0;

        foreach ($rows as $row) {
            if ($leftover < 1) {
                break;
            }

            $order = Order::query()->find($row->order_id);
            if (! $order || $order->isClosed()) {
                $row->status = StockPreorder::CANCELLED;
                $row->closed_at = now();
                $row->save();
                continue;
            }

            $give = min((int) $row->quantity, $leftover);
            if ($give < 1) {
                continue;
            }

            $expiresAt = StockReservation::query()
                ->where('order_id', $order->id)
                ->where('status', StockReservation::ACTIVE)
                ->min('expires_at');

            $this->addReservationUnits(
                $order,
                $locked,
                $give,
                $expiresAt ? \Illuminate\Support\Carbon::parse($expiresAt) : now()->addHours(max(1, (int) ShopSetting::current()->unpaid_expiry_hours)),
                $actor,
                'admin',
            );

            $leftover -= $give;
            $units += $give;
            $row->quantity = (int) $row->quantity - $give;
            if ($row->quantity < 1) {
                $row->quantity = 0;
                $row->status = StockPreorder::ALLOCATED;
                $row->allocated_at = now();
                $row->closed_at = now();
            }
            $row->save();

            $stillWaiting = StockPreorder::query()
                ->where('order_id', $order->id)
                ->where('status', StockPreorder::WAITING)
                ->where('quantity', '>', 0)
                ->exists();

            if (! $stillWaiting) {
                $readyOrderIds[] = (int) $order->id;
            }
        }

        if ($units > 0) {
            ActivityLogger::record(
                $actor,
                'stock.preorder_allocated',
                'File d’attente : '.$units.' pièce(s) attribuée(s) pour '.$this->variantLabel($locked),
                $locked,
                ['units' => $units, 'variant_id' => $locked->id],
                'admin',
            );
        }

        return $readyOrderIds;
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function promoteAllocatedOrders(array $orderIds, ?User $actor = null): void
    {
        foreach ($orderIds as $orderId) {
            DB::transaction(function () use ($orderId, $actor) {
                $order = Order::query()->lockForUpdate()->find($orderId);
                if (! $order || $order->status !== 'acceptée') {
                    return;
                }

                $stillWaiting = StockPreorder::query()
                    ->where('order_id', $order->id)
                    ->where('status', StockPreorder::WAITING)
                    ->where('quantity', '>', 0)
                    ->exists();

                if ($stillWaiting) {
                    return;
                }

                $order->status = 'prête';
                $order->save();
                $this->confirmReservationsFor($order, $actor, 'site');
            });
        }
    }

    private function addReservationUnits(
        Order $order,
        ProductVariant $locked,
        int $quantity,
        $expiresAt,
        ?User $actor,
        string $channel,
    ): void {
        if ($quantity < 1) {
            return;
        }

        $existing = StockReservation::query()
            ->where('order_id', $order->id)
            ->where('product_variant_id', $locked->id)
            ->where('status', StockReservation::ACTIVE)
            ->first();

        if ($existing) {
            $existing->quantity = (int) $existing->quantity + $quantity;
            $existing->save();
        } else {
            StockReservation::query()->create([
                'order_id' => $order->id,
                'product_variant_id' => $locked->id,
                'quantity' => $quantity,
                'status' => StockReservation::ACTIVE,
                'expires_at' => $expiresAt,
            ]);
        }

        $locked->reserved_quantity = max(0, (int) ($locked->reserved_quantity ?? 0)) + $quantity;
        $locked->save();

        $this->writeMovement(
            $locked,
            0,
            'reservation',
            $channel,
            $actor,
            $order,
            'Réservation de commande',
            ['reserved' => $quantity, 'expires_at' => optional($expiresAt)->toIso8601String()],
        );
    }

    private function cancelPreordersFor(Order $order, ?User $actor, string $channel): int
    {
        $rows = StockPreorder::query()
            ->where('order_id', $order->id)
            ->where('status', StockPreorder::WAITING)
            ->lockForUpdate()
            ->get();

        $units = 0;
        foreach ($rows as $row) {
            $units += (int) $row->quantity;
            $row->status = StockPreorder::CANCELLED;
            $row->closed_at = now();
            $row->save();

            $variant = ProductVariant::query()->find($row->product_variant_id);
            if ($variant) {
                $this->writeMovement(
                    $variant,
                    0,
                    'precommande_annulee',
                    $channel,
                    $actor,
                    $order,
                    'Précommande retirée de la file',
                    ['preorder_id' => $row->id, 'released' => (int) $row->quantity],
                );
            }
        }

        return $units;
    }

    /**
     * @return list<int>
     */
    private function orderVariantIds(Order $order): array
    {
        $order->loadMissing('items');

        return $order->items
            ->pluck('product_variant_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function variantLabel(ProductVariant $variant): string
    {
        $variant->loadMissing('product');

        return trim(($variant->product?->name ?? 'Produit').' · '.$variant->name);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function writeMovement(
        ProductVariant $variant,
        int $quantity,
        string $type,
        string $channel,
        ?User $actor,
        ?Model $reference,
        ?string $reason,
        array $properties = [],
    ): void {
        $onHand = $variant->stock_quantity === null ? 0 : max(0, (int) $variant->stock_quantity);

        StockMovement::query()->create([
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'quantity_after' => $onHand,
            'type' => $type,
            'channel' => $channel,
            'reason' => $reason,
            'user_id' => $actor?->id,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'properties' => $properties === [] ? null : $properties,
        ]);
    }
}
