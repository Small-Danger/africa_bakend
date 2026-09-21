<?php

use App\Mail\OrderStatusMail;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    ShopSetting::current();
    Mail::fake();
});

function notifyVariant(): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-notif-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-notif-'.uniqid(),
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
        'stock_quantity' => 10,
        'reserved_quantity' => 0,
        'is_active' => true,
    ]);
}

function notifyOrder(?User $client = null): Order
{
    $variant = notifyVariant();
    $client ??= User::factory()->create();

    $order = Order::query()->create([
        'client_id' => $client->id,
        'total_amount' => 10000,
        'status' => 'en_attente',
        'channel' => 'en_ligne',
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price' => 10000,
        'total_price' => 10000,
    ]);

    return $order->fresh(['client', 'payments']);
}

test('un acompte crée une notification client et envoie un e-mail', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create(['email' => 'awa@example.com']);
    $order = notifyOrder($client);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'wave',
            'amount' => 4000,
        ])
        ->assertCreated();

    expect(Notification::query()->where('user_id', $client->id)->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $client->id)->value('title'))->toBe('Acompte reçu');

    Mail::assertSent(OrderStatusMail::class, function (OrderStatusMail $mail) use ($client) {
        return $mail->hasTo($client->email) && str_contains($mail->heading, 'Acompte');
    });
});

test('l’e-mail ne part pas si le canal e-mail est coupé', function () {
    $settings = ShopSetting::current();
    $settings->notify_email = false;
    $settings->save();

    $admin = User::factory()->admin()->create();
    $client = User::factory()->create(['email' => 'awa@example.com']);
    $order = notifyOrder($client);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/admin/orders/'.$order->id.'/payments', [
            'method' => 'wave',
            'amount' => 4000,
        ])
        ->assertCreated();

    expect(Notification::query()->where('user_id', $client->id)->count())->toBe(1);
    Mail::assertNothingSent();
});

test('accepter une commande notifie le client', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();
    $order = notifyOrder($client);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'acceptée'])
        ->assertOk();

    expect(Notification::query()->where('user_id', $client->id)->value('title'))->toBe('Commande acceptée');
});

test('une commande expirée notifie le client', function () {
    $client = User::factory()->create();
    $order = notifyOrder($client);
    $order->forceFill(['created_at' => now()->subHours(30)])->save();

    app(StockService::class)->expireOverdueReservations();

    expect($order->fresh()->status)->toBe('expirée')
        ->and(Notification::query()->where('user_id', $client->id)->value('title'))->toBe('Commande expirée');
});

test('le client lit ses notifications', function () {
    $admin = User::factory()->admin()->create();
    $client = User::factory()->create();
    $order = notifyOrder($client);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/admin/orders/'.$order->id.'/status', ['status' => 'acceptée'])
        ->assertOk();

    $this->actingAs($client, 'sanctum')
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonPath('data.statistics.unread', 1)
        ->assertJsonPath('data.notifications.0.title', 'Commande acceptée');
});

test('un admin voit les alertes à valider', function () {
    $admin = User::factory()->admin()->create();
    notifyOrder();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/alerts')
        ->assertOk()
        ->assertJsonPath('data.items.0.key', 'to_validate')
        ->assertJsonPath('data.items.0.count', 1);
});
