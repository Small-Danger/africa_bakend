<?php

use App\Models\ShopSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function settingsToken(User $user): string
{
    return $user->createToken('test')->plainTextToken;
}

test('un visiteur non authentifié reçoit 401 JSON', function () {
    $this->get('/api/admin/settings')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('un admin lit les paramètres par défaut', function () {
    $admin = User::factory()->admin()->create();

    $this->withToken(settingsToken($admin))
        ->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('data.settings.preorder_delay_days', 14)
        ->assertJsonPath('data.settings.unpaid_expiry_hours', 24)
        ->assertJsonPath('data.settings.low_stock_threshold', 5)
        ->assertJsonPath('data.settings.min_deposit_percent', 0)
        ->assertJsonPath('data.can_edit_identity', true);
});

test('un gérant peut modifier le délai de précommande mais pas le WhatsApp', function () {
    $gerant = User::factory()->gerant()->create();

    $this->withToken(settingsToken($gerant))
        ->putJson('/api/admin/settings', [
            'preorder_delay_days' => 21,
            'whatsapp_number' => '+22670000000',
        ])
        ->assertOk()
        ->assertJsonPath('data.settings.preorder_delay_days', 21)
        ->assertJsonPath('data.settings.whatsapp_number', '+22663126849')
        ->assertJsonPath('data.can_edit_identity', false);

    expect(ShopSetting::current()->whatsapp_number)->toBe('+22663126849');
});

test('un admin peut activer les modes de paiement', function () {
    $admin = User::factory()->admin()->create();

    $this->withToken(settingsToken($admin))
        ->putJson('/api/admin/settings', [
            'payment_methods' => ['especes', 'wave'],
            'notify_email' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.settings.payment_methods', ['especes', 'wave'])
        ->assertJsonPath('data.settings.notify_email', false);
});

test('une secrétaire n’accède pas aux paramètres', function () {
    $secretaire = User::factory()->secretaire()->create();

    $this->withToken(settingsToken($secretaire))
        ->getJson('/api/admin/settings')
        ->assertForbidden();
});
