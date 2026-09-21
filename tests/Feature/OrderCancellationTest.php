<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    ShopSetting::current();
});

function cancelVariant(int $stock = 8): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-cancel-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-cancel-'.uniqid(),
        'description' => 'Test',
        'base_price' => 10000,
        'category_id' => $category->id,
        'is_active' => true,
        'preorder_allowed' => true,
    ]);

    return ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '250g',
        'price' => 10000,
        'stock_quantity' => $stock,
        'reserved_quantity' => 0,
        'is_active' => true,
    ]);
}

function cancelOrder(ProductVariant $variant, array $overrides = []): Order
{
    $order = Order::query()->create(array_merge([
        'client_id' => User::factory()->create()->id,
        'total_amount' => 10000,
        'status' => 'en_attente',
        'channel' => 'en_ligne',
    ], $overrides));

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);

    return $order->fresh(['items.variant.product', 'payments']);
}

test('annuler sans motif est refusé', function () {
    $admin = User::factory()->admin()->create();
    $order = cancelOrder(cancelVariant());

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'annulée'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Erreur de validation');

    expect($order->fresh()->status)->toBe('en_attente');
});

test('annuler une commande non payée exige un motif et libère le stock', function () {
    $admin = User::factory()->admin()->create();
    $variant = cancelVariant(8);
    $order = cancelOrder($variant);
    app(StockService::class)->reserveForOrder($order, $admin);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', [
            'status' => 'annulée',
            'cancellation_reason' => 'Client injoignable',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'annulée')
        ->assertJsonPath('data.cancellation.reason', 'Client injoignable')
        ->assertJsonPath('data.cancellation.cancelled_by', $admin->name)
        ->assertJsonPath('data.refunded_amount', 0)
        ->assertJsonPath('data.payment_status', 'non_paye');

    expect($variant->fresh()->reserved_quantity)->toBe(0)
        ->and($order->fresh()->cancelled_by)->toBe($admin->id)
        ->and(ActivityLog::query()->where('action', 'order.cancelled')->count())->toBe(1)
        ->and(OrderPayment::query()->where('order_id', $order->id)->where('method', 'avoir')->count())->toBe(0);
});

test('annuler une commande avec acompte crée un avoir', function () {
    $admin = User::factory()->admin()->create();
    $order = cancelOrder(cancelVariant());

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'wave',
            'amount' => 4000,
        ])
        ->assertCreated();

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', [
            'status' => 'annulée',
            'cancellation_reason' => 'Rupture fournisseur',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'annulée')
        ->assertJsonPath('data.payment_status', 'rembourse')
        ->assertJsonPath('data.paid_amount', 4000)
        ->assertJsonPath('data.refunded_amount', 4000);

    expect(OrderPayment::query()->where('order_id', $order->id)->where('method', 'avoir')->sum('amount'))->toBe(4000);
});

test('une secrétaire ne peut toujours pas annuler', function () {
    $secretary = User::factory()->secretaire()->create();
    $order = cancelOrder(cancelVariant());

    $this->actingAs($secretary, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', [
            'status' => 'annulée',
            'cancellation_reason' => 'Demande du client',
        ])
        ->assertForbidden();
});

test('on ne peut pas enregistrer un avoir à la main', function () {
    $admin = User::factory()->admin()->create();
    $order = cancelOrder(cancelVariant());

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'avoir',
            'amount' => 2000,
        ])
        ->assertStatus(422);
});
