<?php

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\RefreshDatabaseSafe;

uses(RefreshDatabaseSafe::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function activityToken(User $user): string
{
    return $user->createToken('test')->plainTextToken;
}

test('une secrétaire n’accède pas au journal', function () {
    $secretaire = User::factory()->secretaire()->create();

    $this->withToken(activityToken($secretaire))
        ->getJson('/api/admin/activity')
        ->assertForbidden();
});

test('créer un compte équipe écrit une ligne dans le journal', function () {
    $admin = User::factory()->admin()->create();

    $this->withToken(activityToken($admin))
        ->postJson('/api/admin/team', [
            'name' => 'Awa Gerant',
            'email' => 'awa.journal@afrikraga.com',
            'password' => 'password123',
            'role' => 'gerant',
        ])
        ->assertCreated();

    $this->withToken(activityToken($admin))
        ->getJson('/api/admin/activity')
        ->assertOk()
        ->assertJsonPath('data.entries.0.action', 'team.created')
        ->assertJsonPath('data.entries.0.actor.id', $admin->id);

    expect(ActivityLog::query()->where('action', 'team.created')->count())->toBe(1)
        ->and(ActivityLog::query()->first()->properties['email'] ?? null)->toBe('awa.journal@afrikraga.com')
        ->and(json_encode(ActivityLog::query()->first()->properties))->not->toContain('password');
});

test('modifier les paramètres écrit une ligne dans le journal', function () {
    $gerant = User::factory()->gerant()->create();

    $this->withToken(activityToken($gerant))
        ->putJson('/api/admin/settings', [
            'preorder_delay_days' => 21,
        ])
        ->assertOk();

    expect(ActivityLog::query()->where('action', 'settings.updated')->count())->toBe(1);
});
