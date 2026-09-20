<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
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

function expiryVariant(int $stock = 8): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-exp-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-exp-'.uniqid(),
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

function expiryOrder(ProductVariant $variant, array $overrides = []): Order
{
    $order = Order::query()->create(array_merge([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 5000,
        'status' => 'en_attente',
        'channel' => 'en_ligne',
    ], $overrides));

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 5000,
        'total_price' => 5000,
    ]);

    return $order->fresh(['items.variant.product', 'payments']);
}

test('une commande site non payée et trop ancienne expire même sans réservation', function () {
    $variant = expiryVariant();
    $order = expiryOrder($variant);
    $order->forceFill(['created_at' => now()->subHours(25)])->save();

    expect(app(StockService::class)->expireOverdueReservations())->toBe(1)
        ->and($order->fresh()->status)->toBe('expirée')
        ->and($order->fresh()->cancellation_reason)->toBe('Commande non payée expirée')
        ->and(ActivityLog::query()->where('action', 'order.expired')->count())->toBe(1);
});

test('un acompte protège la commande même si la réservation est dépassée', function () {
    $variant = expiryVariant();
    $order = expiryOrder($variant);
    app(StockService::class)->reserveForOrder($order);
    StockReservation::query()->where('order_id', $order->id)->update([
        'expires_at' => now()->subHour(),
    ]);
    OrderPayment::query()->create([
        'order_id' => $order->id,
        'method' => 'wave',
        'amount' => 2000,
    ]);

    expect(app(StockService::class)->expireOverdueReservations())->toBe(0)
        ->and($order->fresh()->status)->toBe('en_attente')
        ->and($variant->fresh()->reserved_quantity)->toBe(1);
});

test('une vente boutique n’expire pas automatiquement', function () {
    $variant = expiryVariant();
    $order = expiryOrder($variant, [
        'channel' => 'boutique',
        'status' => 'disponible',
    ]);
    $order->forceFill(['created_at' => now()->subDays(3)])->save();

    expect(app(StockService::class)->expireOverdueReservations())->toBe(0)
        ->and($order->fresh()->status)->toBe('disponible');
});

test('une commande récente non payée n’expire pas', function () {
    $variant = expiryVariant();
    $order = expiryOrder($variant);
    app(StockService::class)->reserveForOrder($order);

    expect(app(StockService::class)->expireOverdueReservations())->toBe(0)
        ->and($order->fresh()->status)->toBe('en_attente')
        ->and($variant->fresh()->reserved_quantity)->toBe(1);
});

test('on ne peut pas enregistrer un paiement sur une commande expirée', function () {
    $secretary = User::factory()->secretaire()->create();
    $variant = expiryVariant();
    $order = expiryOrder($variant);
    $order->forceFill(['created_at' => now()->subHours(30)])->save();

    app(StockService::class)->expireOverdueReservations();

    $this->actingAs($secretary, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'wave',
            'amount' => 2000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Impossible d’enregistrer un paiement sur une commande expirée');
});

test('le rapport finance exclut les commandes expirées', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();
    $now = now();

    Order::query()->create([
        'client_id' => $client->id,
        'total_amount' => 80000,
        'status' => 'acceptée',
    ]);
    Order::query()->create([
        'client_id' => $client->id,
        'total_amount' => 25000,
        'status' => 'expirée',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/finance/month?year='.$now->year.'&month='.$now->month)
        ->assertOk()
        ->assertJsonPath('data.sales', 80000)
        ->assertJsonPath('data.orders_count', 1);
});
