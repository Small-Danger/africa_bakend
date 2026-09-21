<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    ShopSetting::current();
});

function reservationVariant(int $stock = 10): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-res-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-res-'.uniqid(),
        'description' => 'Test',
        'base_price' => 5000,
        'category_id' => $category->id,
        'is_active' => true,
        'preorder_allowed' => true,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '250g',
        'price' => 5000,
        'stock_quantity' => $stock,
        'reserved_quantity' => 0,
        'is_active' => true,
    ]);
}

function reservationOrder(ProductVariant $variant, int $quantity = 3, string $status = 'en_attente'): Order
{
    $order = Order::query()->create([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 5000 * $quantity,
        'status' => $status,
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'unit_price' => 5000,
        'total_price' => 5000 * $quantity,
    ]);

    return $order->fresh(['items.variant.product']);
}

test('une commande site réserve le stock sans le débiter', function () {
    $variant = reservationVariant(10);
    $order = reservationOrder($variant, 3);

    app(StockService::class)->reserveForOrder($order);

    $fresh = $variant->fresh();
    $snap = app(StockService::class)->snapshot($fresh);

    expect($fresh->stock_quantity)->toBe(10)
        ->and($fresh->reserved_quantity)->toBe(3)
        ->and($snap['available'])->toBe(7)
        ->and(StockReservation::query()->where('order_id', $order->id)->where('status', 'active')->count())->toBe(1);
});

test('deux commandes ne peuvent pas réserver les mêmes pièces', function () {
    $variant = reservationVariant(5);
    $first = reservationOrder($variant, 5);
    $second = reservationOrder($variant, 5);

    $stock = app(StockService::class);
    $stock->reserveForOrder($first);
    $stock->reserveForOrder($second);

    expect($variant->fresh()->reserved_quantity)->toBe(5)
        ->and(StockReservation::query()->where('order_id', $first->id)->sum('quantity'))->toBe(5)
        ->and(StockReservation::query()->where('order_id', $second->id)->sum('quantity'))->toBe(0);
});

test('confirmer une commande débite le stock réservé', function () {
    $variant = reservationVariant(10);
    $order = reservationOrder($variant, 4);
    $admin = User::factory()->admin()->create();
    $stock = app(StockService::class);

    $stock->reserveForOrder($order, $admin);
    $stock->syncOrderHold($order, 'disponible', $admin);

    $fresh = $variant->fresh();
    expect($fresh->stock_quantity)->toBe(6)
        ->and($fresh->reserved_quantity)->toBe(0)
        ->and(StockReservation::query()->where('order_id', $order->id)->value('status'))->toBe('confirmed');
});

test('annuler une commande réservée libère le stock sans le recréditer', function () {
    $variant = reservationVariant(10);
    $order = reservationOrder($variant, 4);
    $admin = User::factory()->admin()->create();
    $stock = app(StockService::class);

    $stock->reserveForOrder($order, $admin);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', [
            'status' => 'annulée',
            'cancellation_reason' => 'Client a changé d’avis',
        ])
        ->assertOk();

    $fresh = $variant->fresh();
    expect($fresh->stock_quantity)->toBe(10)
        ->and($fresh->reserved_quantity)->toBe(0)
        ->and($order->fresh()->status)->toBe('annulée');
});

test('une réservation expirée clôture la commande et libère le stock', function () {
    $variant = reservationVariant(8);
    $order = reservationOrder($variant, 3);
    $stock = app(StockService::class);
    $stock->reserveForOrder($order);

    StockReservation::query()->where('order_id', $order->id)->update([
        'expires_at' => now()->subHour(),
    ]);

    expect($stock->expireOverdueReservations())->toBe(1)
        ->and($variant->fresh()->reserved_quantity)->toBe(0)
        ->and($variant->fresh()->stock_quantity)->toBe(8)
        ->and($order->fresh()->status)->toBe('expirée')
        ->and($order->fresh()->cancellation_reason)->toBe('Réservation expirée');
});
