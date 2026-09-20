<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Stock\StockState;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function stockPageVariant(array $attrs = []): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-stock-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => $attrs['product_name'] ?? 'Beurre de karité',
        'slug' => 'beurre-stock-'.uniqid(),
        'description' => 'Test',
        'base_price' => 5000,
        'category_id' => $category->id,
        'is_active' => true,
        'preorder_allowed' => $attrs['preorder_allowed'] ?? true,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => $attrs['name'] ?? '250g',
        'price' => 5000,
        'stock_quantity' => array_key_exists('stock_quantity', $attrs) ? $attrs['stock_quantity'] : 10,
        'is_active' => true,
    ]);
}

test('un visiteur non authentifié reçoit 401 JSON', function () {
    $this->get('/api/admin/stock')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('un admin liste le stock avec les quantités', function () {
    stockPageVariant(['stock_quantity' => 8, 'product_name' => 'Huile de baobab']);
    $admin = User::factory()->admin()->create();

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->getJson('/api/admin/stock')
        ->assertOk()
        ->assertJsonPath('data.can_view_quantities', true)
        ->assertJsonPath('data.can_adjust', true)
        ->assertJsonPath('data.summary.en_stock', 1)
        ->assertJsonPath('data.items.0.stock_quantity', 8)
        ->assertJsonPath('data.items.0.product_name', 'Huile de baobab');
});

test('une secrétaire voit l’état sans la quantité et ne peut pas corriger', function () {
    stockPageVariant(['stock_quantity' => 20]);
    $secretaire = User::factory()->secretaire()->create();
    $token = $secretaire->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/stock')
        ->assertOk()
        ->assertJsonPath('data.can_view_quantities', false)
        ->assertJsonPath('data.can_adjust', false)
        ->assertJsonPath('data.items.0.stock_status', StockState::EN_STOCK)
        ->assertJsonPath('data.items.0.stock_label', 'En stock')
        ->assertJsonMissingPath('data.items.0.stock_quantity');

    $variantId = ProductVariant::query()->value('id');

    $this->withToken($token)
        ->putJson('/api/admin/stock/'.$variantId, ['quantity' => 20])
        ->assertForbidden();

    expect(ProductVariant::query()->find($variantId)->stock_quantity)->toBe(20);
});

test('un caissier n’accède pas à l’écran stock admin', function () {
    $caissiere = User::factory()->caissiere()->create();

    $this->withToken($caissiere->createToken('test')->plainTextToken)
        ->getJson('/api/admin/stock')
        ->assertForbidden();
});

test('un admin peut inventorier une variante encore nulle', function () {
    $variant = stockPageVariant(['stock_quantity' => null]);
    $admin = User::factory()->admin()->create();

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->putJson('/api/admin/stock/'.$variant->id, [
            'quantity' => 12,
            'reason' => 'Comptage rayon',
        ])
        ->assertOk()
        ->assertJsonPath('data.item.stock_quantity', 12)
        ->assertJsonPath('data.item.needs_inventory', false);

    expect($variant->fresh()->stock_quantity)->toBe(12)
        ->and(ActivityLog::query()->where('action', 'stock.adjusted')->count())->toBe(1);
});

test('le filtre à inventorier ne retourne que les stocks nuls', function () {
    stockPageVariant(['stock_quantity' => 5]);
    stockPageVariant(['stock_quantity' => null, 'product_name' => 'Savon noir']);
    $admin = User::factory()->admin()->create();

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->getJson('/api/admin/stock?status=needs_inventory')
        ->assertOk()
        ->assertJsonPath('data.summary.needs_inventory', 1)
        ->assertJsonPath('data.items.0.needs_inventory', true)
        ->assertJsonPath('data.items.0.product_name', 'Savon noir');
});
