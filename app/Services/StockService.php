<?php

namespace App\Services;

use App\Authorization\Permissions;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\StockMovement;
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
            $state = StockState::EN_STOCK;
            $label = 'En stock (à inventorier)';
        } elseif ($available > 0) {
            $state = StockState::EN_STOCK;
            $label = $available <= $threshold ? 'Plus que '.$available : 'En stock';
        } elseif ($preorderAllowed) {
            $state = StockState::SUR_COMMANDE;
            $label = 'Pas en stock, commandable, disponible sous '.$delay.' jours';
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
                'stock_status' => StockState::EN_STOCK,
                'stock_label' => 'En stock',
                'needs_inventory' => true,
                'is_available' => true,
            ];
        }

        $snaps = $variants->map(fn (ProductVariant $variant) => $this->snapshot($variant));

        if ($snaps->contains(fn (array $snap) => $snap['state'] === StockState::EN_STOCK)) {
            $inStock = $snaps->filter(fn (array $snap) => $snap['state'] === StockState::EN_STOCK);
            $needsInventory = $inStock->every(fn (array $snap) => $snap['needs_inventory']);

            return [
                'stock_status' => StockState::EN_STOCK,
                'stock_label' => $needsInventory ? 'En stock (à inventorier)' : 'En stock',
                'needs_inventory' => $needsInventory,
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

        return DB::transaction(function () use ($variant, $newQuantity, $actor, $reason) {
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

            return $fresh;
        });
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
