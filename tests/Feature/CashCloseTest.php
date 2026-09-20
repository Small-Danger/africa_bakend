<?php

use App\Models\CashSession;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function closeVariant(): ProductVariant
{
    $category = Category::query()->create([
        'name' => 'Karité',
        'slug' => 'karite-close-'.uniqid(),
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $product = Product::query()->create([
        'name' => 'Savon noir',
        'slug' => 'savon-close-'.uniqid(),
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
        'stock_quantity' => 20,
        'reserved_quantity' => 0,
        'is_active' => true,
    ]);
}

function openCashierSession(User $cashier, int $opening = 10000): CashSession
{
    return CashSession::query()->create([
        'cashier_id' => $cashier->id,
        'opening_amount' => $opening,
        'opened_at' => now(),
    ]);
}

test('la session ouverte expose le bilan en direct', function () {
    $cashier = User::factory()->caissiere()->create();
    $variant = closeVariant();
    $session = openCashierSession($cashier, 10000);

    $client = User::factory()->create();

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/pos/orders', [
            'client_id' => $client->id,
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'unit_price' => 5000,
            ]],
            'payments' => [
                ['method' => 'especes', 'amount' => 3000],
                ['method' => 'wave', 'amount' => 2000],
            ],
        ])
        ->assertCreated();

    $this->actingAs($cashier, 'sanctum')
        ->getJson('/api/pos/cash-session/current')
        ->assertOk()
        ->assertJsonPath('data.report.sales_count', 1)
        ->assertJsonPath('data.report.sales_total', 5000)
        ->assertJsonPath('data.report.payments.especes', 3000)
        ->assertJsonPath('data.report.payments.wave', 2000)
        ->assertJsonPath('data.report.expected_cash', 13000)
        ->assertJsonPath('data.is_open', true);
});

test('la clôture fige le bilan et calcule l’écart', function () {
    $cashier = User::factory()->caissiere()->create();
    $variant = closeVariant();
    openCashierSession($cashier, 10000);

    $client = User::factory()->create();

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/pos/orders', [
            'client_id' => $client->id,
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'unit_price' => 5000,
            ]],
            'payments' => [['method' => 'especes', 'amount' => 5000]],
        ])
        ->assertCreated();

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/pos/cash-session/close', [
            'closing_amount_counted' => 14000,
            'notes' => 'Manque 1000',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_open', false)
        ->assertJsonPath('data.report.expected_cash', 15000)
        ->assertJsonPath('data.report.counted_cash', 14000)
        ->assertJsonPath('data.report.discrepancy', -1000);

    expect(CashSession::query()->whereNotNull('closed_at')->count())->toBe(1)
        ->and(CashSession::query()->value('report')['sales_total'])->toBe(5000);
});

test('un admin lit les clôtures, une secrétaire non', function () {
    $cashier = User::factory()->caissiere()->create();
    $session = openCashierSession($cashier, 5000);
    $session->update([
        'closed_at' => now(),
        'closing_amount_expected' => 5000,
        'closing_amount_counted' => 5000,
        'discrepancy' => 0,
        'report' => [
            'sales_count' => 0,
            'sales_total' => 0,
            'expected_cash' => 5000,
            'counted_cash' => 5000,
            'discrepancy' => 0,
        ],
    ]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/finance/closes')
        ->assertOk()
        ->assertJsonPath('data.summary.closed_count', 1)
        ->assertJsonPath('data.items.0.cashier_name', $cashier->name);

    $secretaire = User::factory()->secretaire()->create();
    $this->actingAs($secretaire, 'sanctum')
        ->getJson('/api/admin/finance/closes')
        ->assertForbidden();
});
