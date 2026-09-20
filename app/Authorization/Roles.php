<?php

namespace App\Authorization;

final class Roles
{
    public const ADMIN = 'admin';

    public const GERANT = 'gerant';

    public const SECRETAIRE = 'secretaire';

    public const CAISSIERE = 'caissiere';

    public const CLIENT = 'client';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::ADMIN => 'Administrateur',
            self::GERANT => 'Gérant',
            self::SECRETAIRE => 'Secrétaire',
            self::CAISSIERE => 'Caissier',
            self::CLIENT => 'Client',
        ];
    }

    /**
     * @return list<string>
     */
    public static function staff(): array
    {
        return [self::ADMIN, self::GERANT, self::SECRETAIRE, self::CAISSIERE];
    }

    /**
     * @return list<string>
     */
    public static function backoffice(): array
    {
        return [self::ADMIN, self::GERANT, self::SECRETAIRE];
    }

    /**
     * @return list<string>
     */
    public static function pos(): array
    {
        return [self::ADMIN, self::GERANT, self::CAISSIERE];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function label(string $role): string
    {
        return self::labels()[$role] ?? $role;
    }

    /**
     * @return list<string>
     */
    public static function posPinRoles(): array
    {
        return self::pos();
    }
}
