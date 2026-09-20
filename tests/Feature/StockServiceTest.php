<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Stock\StockState;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function makeCatalogVariant(array $attrs = []): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Beurre de karité',
        'slug' => 'beurre-'.uniqid(),
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

test('un stock null reste à inventorier et n’est pas converti en 0', function () {
    $variant = makeCatalogVariant(['stock_quantity' => null]);
    $snap = app(StockService::class)->snapshot($variant);

    expect($variant->fresh()->stock_quantity)->toBeNull()
        ->and($snap['unlimited_legacy'])->toBeTrue()
        ->and($snap['needs_inventory'])->toBeTrue()
        ->and($snap['state'])->toBe(StockState::EN_STOCK)
        ->and($snap['label'])->toBe('En stock (à inventorier)');
});

test('zéro avec précommande autorisée donne sur commande', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 0, 'preorder_allowed' => true]);
    $snap = app(StockService::class)->snapshot($variant);

    expect($snap['state'])->toBe(StockState::SUR_COMMANDE)
        ->and($variant->isAvailable())->toBeTrue();
});

test('zéro sans précommande donne rupture', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 0, 'preorder_allowed' => false]);
    $snap = app(StockService::class)->snapshot($variant);

    expect($snap['state'])->toBe(StockState::RUPTURE)
        ->and($variant->isAvailable())->toBeFalse();
});

test('une vente ne rend jamais le stock négatif et enregistre le mouvement', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 2]);
    $order = Order::query()->create([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 25000,
        'status' => 'en_attente',
    ]);

    app(StockService::class)->commitSale($variant, 5, 'site', null, $order);

    expect($variant->fresh()->stock_quantity)->toBe(0);

    $movement = StockMovement::query()->where('product_variant_id', $variant->id)->first();
    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(-2)
        ->and($movement->quantity_after)->toBe(0);
});

test('l’annulation remet uniquement la quantité réellement sortie', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 4]);
    $order = Order::query()->create([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 10000,
        'status' => 'disponible',
    ]);

    $stock = app(StockService::class);
    $stock->commitSale($variant, 3, 'pos', null, $order);
    expect($variant->fresh()->stock_quantity)->toBe(1);

    $stock->reverseSalesFor($order, 'pos');
    expect($variant->fresh()->stock_quantity)->toBe(4);
});

test('le site public n’expose pas la quantité', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 12]);

    $this->getJson('/api/products/'.$variant->product_id)
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_status', StockState::EN_STOCK)
        ->assertJsonPath('data.variants.0.stock_quantity', null)
        ->assertJsonPath('data.variants.0.needs_inventory', false);
});

test('un admin voit la quantité des variantes', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 8]);
    $admin = User::factory()->admin()->create();

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->getJson('/api/admin/products/'.$variant->product_id.'/variants')
        ->assertOk()
        ->assertJsonPath('data.variants.0.stock_quantity', 8)
        ->assertJsonPath('data.variants.0.stock_status', StockState::EN_STOCK);
});

test('un caissier voit l’état POS sans la quantité', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 8]);
    $caissiere = User::factory()->caissiere()->create();

    $this->withToken($caissiere->createToken('test')->plainTextToken)
        ->getJson('/api/pos/products/search?q='.$variant->name)
        ->assertOk()
        ->assertJsonPath('data.0.stock_status', StockState::EN_STOCK)
        ->assertJsonPath('data.0.stock_quantity', null);
});

test('le catalogue public expose l’état agrégé sans quantité', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 0, 'preorder_allowed' => true]);

    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.products.0.stock_status', StockState::SUR_COMMANDE)
        ->assertJsonPath('data.products.0.stock_label', 'Sur commande');

    $this->getJson('/api/products/'.$variant->product_id)
        ->assertOk()
        ->assertJsonPath('data.stock_status', StockState::SUR_COMMANDE)
        ->assertJsonPath('data.variants.0.stock_quantity', null);
});

test('annuler une commande site remet le stock réellement sorti', function () {
    $variant = makeCatalogVariant(['stock_quantity' => 6]);
    $order = Order::query()->create([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 10000,
        'status' => 'en_attente',
    ]);

    app(StockService::class)->commitSale($variant, 2, 'site', null, $order);
    expect($variant->fresh()->stock_quantity)->toBe(4);

    $admin = User::factory()->admin()->create();

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'annulée'])
        ->assertOk()
        ->assertJsonPath('data.status', 'annulée');

    expect($variant->fresh()->stock_quantity)->toBe(6)
        ->and(StockMovement::query()->where('type', 'annulation')->count())->toBe(1);

    $this->withToken($admin->createToken('test')->plainTextToken)
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'annulée'])
        ->assertStatus(422);

    expect($variant->fresh()->stock_quantity)->toBe(6);
});

test('une secrétaire ne peut pas annuler une commande', function () {
    $order = Order::query()->create([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 8000,
        'status' => 'en_attente',
    ]);

    $secretaire = User::factory()->secretaire()->create();

    $this->withToken($secretaire->createToken('test')->plainTextToken)
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'annulée'])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe('en_attente');
});

test('le journal connaît le libellé de correction de stock', function () {
    expect(ActivityLog::actionLabels())->toHaveKey('stock.adjusted')
        ->and(ActivityLog::actionLabels()['stock.adjusted'])->toBe('Stock corrigé')
        ->and(ActivityLog::actionLabels())->toHaveKey('stock.received')
        ->and(ActivityLog::actionLabels()['stock.received'])->toBe('Réception de stock');
});
