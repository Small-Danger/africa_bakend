<?php

namespace App\Authorization;

use App\Models\User;

final class StaffAccess
{
    /**
     * Rôles que l'acteur a le droit de créer / modifier / désactiver.
     *
     * @return list<string>
     */
    public static function assignableRoles(User $actor): array
    {
        if ($actor->hasPermissionTo(Permissions::TEAM_MANAGE)) {
            return [Roles::GERANT, Roles::SECRETAIRE, Roles::CAISSIERE];
        }

        if ($actor->hasPermissionTo(Permissions::TEAM_MANAGE_STAFF)) {
            return [Roles::SECRETAIRE, Roles::CAISSIERE];
        }

        return [];
    }

    /**
     * Rôles visibles dans la liste équipe.
     *
     * @return list<string>
     */
    public static function visibleRoles(User $actor): array
    {
        if ($actor->hasPermissionTo(Permissions::TEAM_MANAGE)) {
            return Roles::staff();
        }

        return self::assignableRoles($actor);
    }

    public static function canManage(User $actor, User $member): bool
    {
        if ($actor->id === $member->id) {
            return false;
        }

        return in_array($member->role, self::assignableRoles($actor), true);
    }
}
