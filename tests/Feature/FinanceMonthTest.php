<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function financeVariant(): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-finance-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-finance-'.uniqid(),
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
        'stock_quantity' => 0,
        'is_active' => true,
    ]);
}

test('une secrétaire n’accède pas au rapport finance', function () {
    $secretaire = User::factory()->secretaire()->create();

    $this->withToken($secretaire->createToken('test')->plainTextToken)
        ->getJson('/api/admin/finance/month')
        ->assertForbidden();
});

test('un admin voit l’investissement du mois face aux ventes hors annulations', function () {
    $admin = User::factory()->admin()->create();
    $variant = financeVariant();
    $now = now();

    app(StockService::class)->receiveReceipt([
        'items' => [['variant_id' => $variant->id, 'quantity' => 10]],
        'merchandise_cost' => 200000,
        'shipping_cost' => 75000,
        'received_at' => $now,
    ], $admin);

    $client = User::factory()->create();
    Order::query()->create([
        'client_id' => $client->id,
        'total_amount' => 80000,
        'status' => 'acceptée',
    ]);
    Order::query()->create([
        'client_id' => $client->id,
        'total_amount' => 50000,
        'status' => 'annulée',
    ]);
    $previous = Order::query()->create([
        'client_id' => $client->id,
        'total_amount' => 90000,
        'status' => 'disponible',
    ]);
    Order::query()->where('id', $previous->id)->update([
        'created_at' => $now->copy()->subMonth(),
        'updated_at' => $now->copy()->subMonth(),
    ]);

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->getJson('/api/admin/finance/month?year='.$now->year.'&month='.$now->month)
        ->assertOk()
        ->assertJsonPath('data.invested', 275000)
        ->assertJsonPath('data.merchandise_cost', 200000)
        ->assertJsonPath('data.shipping_cost', 75000)
        ->assertJsonPath('data.sales', 80000)
        ->assertJsonPath('data.orders_count', 1)
        ->assertJsonPath('data.remaining', 195000)
        ->assertJsonPath('data.recovered', false);
});
