<?php

namespace App\Authorization;

final class RolePermissions
{
    /**
     * Matrice brief §8.2 — les ⚠️ sont tranchés de façon conservative
     * (secrétaire : pas d'annulation, pas de caisse ; caissier : pas de remise).
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $all = Permissions::all();

        return [
            Roles::ADMIN => $all,

            Roles::GERANT => array_values(array_diff($all, [
                Permissions::TEAM_MANAGE,
            ])),

            Roles::SECRETAIRE => [
                Permissions::ACCESS_BACKOFFICE,
                Permissions::STOCK_VIEW_STATUS,
                Permissions::ORDERS_VIEW,
                Permissions::ORDERS_RECORD_PAYMENT,
                Permissions::ORDERS_VALIDATE,
                Permissions::ORDERS_UPDATE_STATUS,
                Permissions::ORDERS_COUNTER_PREORDER,
                Permissions::CUSTOMERS_VIEW,
            ],

            Roles::CAISSIERE => [
                Permissions::STOCK_VIEW_STATUS,
                Permissions::POS_SELL,
            ],

            Roles::CLIENT => [],
        ];
    }

    /**
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        return self::matrix()[$role] ?? [];
    }
}
