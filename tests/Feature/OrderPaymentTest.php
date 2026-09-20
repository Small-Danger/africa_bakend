<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    ShopSetting::current();
});

function paymentVariant(): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-pay-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-pay-'.uniqid(),
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
        'stock_quantity' => 20,
        'reserved_quantity' => 0,
        'is_active' => true,
    ]);
}

function paymentOrder(ProductVariant $variant, int $total = 10000, string $channel = 'en_ligne'): Order
{
    $order = Order::query()->create([
        'client_id' => User::factory()->create()->id,
        'total_amount' => $total,
        'status' => 'en_attente',
        'channel' => $channel,
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => $total,
        'total_price' => $total,
    ]);

    return $order->fresh(['items', 'payments']);
}

test('une secrétaire enregistre un acompte puis le solde', function () {
    $secretary = User::factory()->secretaire()->create();
    $order = paymentOrder(paymentVariant(), 10000);

    $this->actingAs($secretary, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'wave',
            'amount' => 4000,
            'reference' => 'WV-88',
        ])
        ->assertCreated()
        ->assertJsonPath('data.order.payment_status', 'partiel')
        ->assertJsonPath('data.order.paid_amount', 4000)
        ->assertJsonPath('data.order.balance', 6000);

    $this->actingAs($secretary, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'depot',
            'amount' => 6000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.order.payment_status', 'paye')
        ->assertJsonPath('data.order.balance', 0);
});

test('accepter une commande exige l’acompte minimum', function () {
    $admin = User::factory()->admin()->create();
    $settings = ShopSetting::current();
    $settings->min_deposit_percent = 50;
    $settings->save();

    $order = paymentOrder(paymentVariant(), 10000);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'acceptée'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Acompte insuffisant : 5000 FCFA requis (50 %)');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'orange_money',
            'amount' => 5000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.order.can_validate', true);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'acceptée'])
        ->assertOk()
        ->assertJsonPath('data.status', 'acceptée');
});

test('un caissier ne peut pas enregistrer un paiement admin', function () {
    $cashier = User::factory()->caissiere()->create();
    $order = paymentOrder(paymentVariant());

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'especes',
            'amount' => 1000,
        ])
        ->assertForbidden();
});

test('un paiement ne peut pas dépasser le solde', function () {
    $admin = User::factory()->admin()->create();
    $order = paymentOrder(paymentVariant(), 10000);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'especes',
            'amount' => 12000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Le montant dépasse le solde restant (10000 FCFA)');
});

test('la file à valider ignore les ventes de caisse et les commandes soldées', function () {
    $admin = User::factory()->admin()->create();
    $variant = paymentVariant();
    $pending = paymentOrder($variant, 8000);
    $paid = paymentOrder($variant, 5000);
    $pos = paymentOrder($variant, 3000, 'boutique');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$paid->id.'/payments', [
            'method' => 'wave',
            'amount' => 5000,
        ])
        ->assertCreated();

    $list = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/orders?to_validate=1')
        ->assertOk();

    $ids = collect($list->json('data.orders'))->pluck('id');

    expect($ids)->toContain($pending->id)
        ->and($ids)->not->toContain($paid->id)
        ->and($ids)->not->toContain($pos->id)
        ->and($list->json('data.summary.to_validate'))->toBe(1)
        ->and($list->json('data.can_record_payment'))->toBeTrue();
});

test('une vente boutique refuse un paiement admin', function () {
    $admin = User::factory()->admin()->create();
    $order = paymentOrder(paymentVariant(), 4000, 'boutique');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'wave',
            'amount' => 4000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Cette vente de caisse est déjà encaissée');
});
