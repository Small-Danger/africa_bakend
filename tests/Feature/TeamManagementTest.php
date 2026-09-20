<?php

use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function staffToken(User $user): string
{
    return $user->createToken('test')->plainTextToken;
}

test('un visiteur non authentifié reçoit 401 JSON et non une erreur login', function () {
    $this->get('/api/admin/team')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('un admin peut lister l’équipe', function () {
    $admin = User::factory()->admin()->create();
    $gerant = User::factory()->gerant()->create();

    $ids = collect(
        $this->withToken(staffToken($admin))
            ->getJson('/api/admin/team')
            ->assertOk()
            ->json('data.members')
    )->pluck('id');

    expect($ids)->toContain($admin->id)->toContain($gerant->id);
});

test('un admin peut créer un gérant et une secrétaire', function () {
    $admin = User::factory()->admin()->create();

    $this->withToken(staffToken($admin))
        ->postJson('/api/admin/team', [
            'name' => 'Awa Gerant',
            'email' => 'awa.gerant@afrikraga.com',
            'password' => 'password123',
            'role' => Roles::GERANT,
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', Roles::GERANT);

    $this->withToken(staffToken($admin))
        ->postJson('/api/admin/team', [
            'name' => 'Binta Secretaire',
            'email' => 'binta.sec@afrikraga.com',
            'password' => 'password123',
            'role' => Roles::SECRETAIRE,
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', Roles::SECRETAIRE);
});

test('un gérant peut créer une secrétaire mais pas un autre gérant', function () {
    $gerant = User::factory()->gerant()->create();

    $this->withToken(staffToken($gerant))
        ->postJson('/api/admin/team', [
            'name' => 'Salimata',
            'email' => 'sali.sec@afrikraga.com',
            'password' => 'password123',
            'role' => Roles::SECRETAIRE,
        ])
        ->assertCreated();

    $this->withToken(staffToken($gerant))
        ->postJson('/api/admin/team', [
            'name' => 'Autre Gerant',
            'email' => 'autre.gerant@afrikraga.com',
            'password' => 'password123',
            'role' => Roles::GERANT,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
});

test('un gérant ne voit pas les admins dans la liste équipe', function () {
    $admin = User::factory()->admin()->create();
    $gerant = User::factory()->gerant()->create();
    $secretaire = User::factory()->secretaire()->create();

    $response = $this->withToken(staffToken($gerant))
        ->getJson('/api/admin/team')
        ->assertOk();

    $ids = collect($response->json('data.members'))->pluck('id');

    expect($ids)->toContain($secretaire->id)
        ->and($ids)->not->toContain($admin->id)
        ->and($ids)->not->toContain($gerant->id);
});

test('une secrétaire ne peut pas gérer l’équipe', function () {
    $secretaire = User::factory()->secretaire()->create();

    $this->withToken(staffToken($secretaire))
        ->getJson('/api/admin/team')
        ->assertForbidden();
});

test('on ne peut pas désactiver son propre compte', function () {
    $admin = User::factory()->admin()->create();
    $secretaire = User::factory()->secretaire()->create();

    $this->withToken(staffToken($admin))
        ->postJson("/api/admin/team/{$admin->id}/toggle-status")
        ->assertForbidden();

    $this->withToken(staffToken($admin))
        ->postJson("/api/admin/team/{$secretaire->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($secretaire->fresh()->is_active)->toBeFalse();
});

test('un client n’accède pas à l’équipe', function () {
    $client = User::factory()->create();

    $this->withToken(staffToken($client))
        ->getJson('/api/admin/team')
        ->assertForbidden();
});
