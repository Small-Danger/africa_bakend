<?php

namespace App\Models\Concerns;

use App\Authorization\Roles;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

trait HasStaffRoles
{
    /** @var list<string>|null */
    private ?array $permissionNameCache = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function extraPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user');
    }

    public function syncPrimaryRole(): void
    {
        if (! $this->role || ! Schema::hasTable('roles')) {
            return;
        }

        $role = Role::query()->where('name', $this->role)->first();
        if (! $role) {
            return;
        }

        $this->roles()->sync([$role->id]);
        $this->forgetPermissionCache();
    }

    public function hasRole(string $role): bool
    {
        if ($this->role === $role) {
            return true;
        }

        if (! Schema::hasTable('roles')) {
            return false;
        }

        if ($this->relationLoaded('roles')) {
            return $this->roles->contains('name', $role);
        }

        return $this->roles()->where('name', $role)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->permissionNameCache !== null) {
            return $this->permissionNameCache;
        }

        if (! Schema::hasTable('permissions')) {
            return $this->permissionNameCache = [];
        }

        $this->loadMissing(['roles.permissions', 'extraPermissions']);

        $fromRoles = $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->all();

        $direct = $this->extraPermissions->pluck('name')->all();

        return $this->permissionNameCache = array_values(array_unique([...$fromRoles, ...$direct]));
    }

    public function hasPermissionTo(string $permission): bool
    {
        if ($this->hasRole(Roles::ADMIN)) {
            return true;
        }

        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        $names = [];

        if ($this->role) {
            $names[] = $this->role;
        }

        if (Schema::hasTable('roles')) {
            $this->loadMissing('roles');
            foreach ($this->roles as $role) {
                $names[] = $role->name;
            }
        }

        return array_values(array_unique($names));
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionNameCache = null;
    }

    public function isManager(): bool
    {
        return $this->hasRole(Roles::GERANT);
    }

    public function isSecretary(): bool
    {
        return $this->hasRole(Roles::SECRETAIRE);
    }

    public function canAccessBackoffice(): bool
    {
        return $this->hasAnyRole(Roles::backoffice());
    }
}
