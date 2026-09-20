<?php

use App\Authorization\Permissions;
use App\Authorization\Roles;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

test('le seeder crée les cinq rôles', function () {
    expect(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing(Roles::all());
});

test('un admin a toutes les permissions y compris finance et équipe', function () {
    $user = User::factory()->admin()->create();

    expect($user->isAdmin())->toBeTrue()
        ->and($user->canAccessBackoffice())->toBeTrue()
        ->and($user->canAccessPos())->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::FINANCE_VIEW))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::TEAM_MANAGE))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::STOCK_VIEW_QUANTITIES))->toBeTrue();
});

test('un gérant n’a pas le droit de créer d’autres gérants', function () {
    $user = User::factory()->gerant()->create();

    expect($user->isManager())->toBeTrue()
        ->and($user->canAccessBackoffice())->toBeTrue()
        ->and($user->canAccessPos())->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::TEAM_MANAGE_STAFF))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::TEAM_MANAGE))->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::FINANCE_VIEW))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::SETTINGS_MANAGE))->toBeTrue();
});

test('une secrétaire valide les paiements sans voir les quantités ni la finance', function () {
    $user = User::factory()->secretaire()->create();

    expect($user->isSecretary())->toBeTrue()
        ->and($user->canAccessBackoffice())->toBeTrue()
        ->and($user->canAccessPos())->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::ORDERS_VALIDATE))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::ORDERS_RECORD_PAYMENT))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::ORDERS_COUNTER_PREORDER))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::STOCK_VIEW_STATUS))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::STOCK_VIEW_QUANTITIES))->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::FINANCE_VIEW))->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::ORDERS_CANCEL))->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::POS_SELL))->toBeFalse();
});

test('un caissier vend sans voir les quantités ni les commandes admin', function () {
    $user = User::factory()->caissiere()->create();

    expect($user->isCashier())->toBeTrue()
        ->and($user->canAccessBackoffice())->toBeFalse()
        ->and($user->canAccessPos())->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::POS_SELL))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::POS_DISCOUNT))->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::STOCK_VIEW_STATUS))->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::STOCK_VIEW_QUANTITIES))->toBeFalse()
        ->and($user->hasPermissionTo(Permissions::ORDERS_VIEW))->toBeFalse();
});

test('un client n’a aucune permission staff', function () {
    $user = User::factory()->create();

    expect($user->isClient())->toBeTrue()
        ->and($user->permissionNames())->toBeEmpty()
        ->and($user->canAccessBackoffice())->toBeFalse()
        ->and($user->canAccessPos())->toBeFalse();
});

test('le profil API expose rôles et permissions', function () {
    $user = User::factory()->secretaire()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.role', Roles::SECRETAIRE)
        ->assertJsonPath('data.user.can_access_backoffice', true)
        ->assertJsonPath('data.user.can_access_pos', false)
        ->assertJsonFragment(['orders.validate']);
});

test('changer le rôle utilisateur resynchronise les permissions', function () {
    $user = User::factory()->create();

    expect($user->hasPermissionTo(Permissions::POS_SELL))->toBeFalse();

    $user->update(['role' => Roles::CAISSIERE]);
    $user->forgetPermissionCache();
    $user->unsetRelation('roles');
    $user->unsetRelation('extraPermissions');

    expect($user->isCashier())->toBeTrue()
        ->and($user->hasPermissionTo(Permissions::POS_SELL))->toBeTrue();
});
