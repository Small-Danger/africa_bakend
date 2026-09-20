<?php

namespace Database\Seeders;

use App\Authorization\Permissions;
use App\Authorization\RolePermissions;
use App\Authorization\Roles;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = [];

        foreach (Permissions::catalog() as $name => $meta) {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                [
                    'label' => $meta['label'],
                    'group' => $meta['group'],
                ]
            );
            $permissionIds[$name] = $permission->id;
        }

        foreach (Roles::labels() as $name => $label) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $label]
            );

            $names = RolePermissions::forRole($name);
            $ids = array_values(array_filter(
                array_map(fn (string $permission) => $permissionIds[$permission] ?? null, $names)
            ));

            $role->permissions()->sync($ids);
        }

        User::query()->each(function (User $user) {
            $user->syncPrimaryRole();
        });
    }
}
