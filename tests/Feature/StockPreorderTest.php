<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\StockPreorder;
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

function preorderVariant(int $stock = 0, bool $allowed = true): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-pre-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-pre-'.uniqid(),
        'description' => 'Test',
        'base_price' => 5000,
        'category_id' => $category->id,
        'is_active' => true,
        'preorder_allowed' => $allowed,
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

function preorderOrder(ProductVariant $variant, int $quantity = 3, string $status = 'en_attente'): Order
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

test('une commande sans stock entre en file sans débiter', function () {
    $variant = preorderVariant(0);
    $order = preorderOrder($variant, 4);

    app(StockService::class)->reserveForOrder($order);

    expect($variant->fresh()->stock_quantity)->toBe(0)
        ->and($variant->fresh()->reserved_quantity)->toBe(0)
        ->and(StockReservation::query()->where('order_id', $order->id)->count())->toBe(0)
        ->and(StockPreorder::query()->where('order_id', $order->id)->where('status', 'waiting')->value('quantity'))->toBe(4);
});

test('un arrivage sert la file dans l’ordre d’arrivée', function () {
    $variant = preorderVariant(0);
    $first = preorderOrder($variant, 3);
    $second = preorderOrder($variant, 2);
    $admin = User::factory()->admin()->create();
    $stock = app(StockService::class);

    $stock->reserveForOrder($first);
    $stock->reserveForOrder($second);

    $stock->receiveReceipt([
        'items' => [['variant_id' => $variant->id, 'quantity' => 3]],
        'merchandise_cost' => 10000,
        'shipping_cost' => 0,
    ], $admin);

    expect(StockPreorder::query()->where('order_id', $first->id)->value('status'))->toBe('allocated')
        ->and(StockPreorder::query()->where('order_id', $second->id)->value('status'))->toBe('waiting')
        ->and(StockPreorder::query()->where('order_id', $second->id)->value('quantity'))->toBe(2)
        ->and(StockReservation::query()->where('order_id', $first->id)->where('status', 'active')->sum('quantity'))->toBe(3)
        ->and($variant->fresh()->reserved_quantity)->toBe(3)
        ->and($variant->fresh()->stock_quantity)->toBe(3);
});

test('une commande acceptée pleinement servie passe prête et débite le stock', function () {
    $variant = preorderVariant(0);
    $order = preorderOrder($variant, 2, 'acceptée');
    $admin = User::factory()->admin()->create();
    $stock = app(StockService::class);

    $stock->reserveForOrder($order);

    $stock->receiveReceipt([
        'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
    ], $admin);

    expect($order->fresh()->status)->toBe('prête')
        ->and($variant->fresh()->stock_quantity)->toBe(0)
        ->and($variant->fresh()->reserved_quantity)->toBe(0)
        ->and(StockReservation::query()->where('order_id', $order->id)->value('status'))->toBe('confirmed');
});

test('annuler une précommande la retire de la file', function () {
    $variant = preorderVariant(0);
    $order = preorderOrder($variant, 3);
    $admin = User::factory()->admin()->create();
    $stock = app(StockService::class);
    $stock->reserveForOrder($order);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', [
            'status' => 'annulée',
            'cancellation_reason' => 'Précommande abandonnée',
        ])
        ->assertOk();

    expect(StockPreorder::query()->where('order_id', $order->id)->value('status'))->toBe('cancelled')
        ->and($order->fresh()->status)->toBe('annulée');
});

test('une précommande expirée clôture la commande même sans réservation', function () {
    $variant = preorderVariant(0);
    $order = preorderOrder($variant, 2);
    $stock = app(StockService::class);
    $stock->reserveForOrder($order);

    StockPreorder::query()->where('order_id', $order->id)->update([
        'expires_at' => now()->subHour(),
    ]);

    expect($stock->expireOverdueReservations())->toBe(1)
        ->and($order->fresh()->status)->toBe('expirée')
        ->and($order->fresh()->cancellation_reason)->toBe('Précommande expirée')
        ->and(StockPreorder::query()->where('order_id', $order->id)->value('status'))->toBe('cancelled');
});

test('une secrétaire peut enregistrer une précommande au comptoir', function () {
    $variant = preorderVariant(0);
    $secretaire = User::factory()->secretaire()->create();

    $this->actingAs($secretaire, 'sanctum')
        ->postJson('/api/admin/orders/counter-preorder', [
            'walk_in_name' => 'Awa Traoré',
            'walk_in_phone' => '+22670000000',
            'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.order.preorder.status', 'waiting')
        ->assertJsonPath('data.order.preorder.units', 2);

    expect(Order::query()->where('walk_in_name', 'Awa Traoré')->exists())->toBeTrue();
});

test('un caissier ne peut pas enregistrer une précommande au comptoir', function () {
    $variant = preorderVariant(0);
    $caissiere = User::factory()->caissiere()->create();

    $this->actingAs($caissiere, 'sanctum')
        ->postJson('/api/admin/orders/counter-preorder', [
            'walk_in_name' => 'Client caisse',
            'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        ])
        ->assertForbidden();
});
