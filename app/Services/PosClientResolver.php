<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PosClientResolver
{
    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone));
    }

    public static function searchByPhone(string $phone, int $limit = 10): Collection
    {
        $phone = trim($phone);

        if ($phone === '') {
            return collect();
        }

        $normalized = self::normalizePhone($phone);
        $lastDigits = strlen($normalized) >= 8 ? substr($normalized, -8) : $normalized;

        return User::query()
            ->where('role', 'client')
            ->where(function ($query) use ($phone, $normalized, $lastDigits) {
                $query->where('whatsapp_phone', 'ilike', "%{$phone}%");

                if (Schema::hasColumn('users', 'phone')) {
                    $query->orWhere('phone', 'ilike', "%{$phone}%");
                }

                if ($normalized !== '' && $normalized !== $phone) {
                    $query->orWhere('whatsapp_phone', 'ilike', "%{$normalized}%");

                    if (Schema::hasColumn('users', 'phone')) {
                        $query->orWhere('phone', 'ilike', "%{$normalized}%");
                    }
                }

                if ($lastDigits !== '' && strlen($lastDigits) >= 4) {
                    $query->orWhere('whatsapp_phone', 'ilike', "%{$lastDigits}%");

                    if (Schema::hasColumn('users', 'phone')) {
                        $query->orWhere('phone', 'ilike', "%{$lastDigits}%");
                    }
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'phone', 'whatsapp_phone', 'email'])
            ->map(fn (User $client) => self::formatClient($client));
    }

    public static function findExactByPhone(string $phone): ?User
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $normalized = self::normalizePhone($phone);

        return User::query()
            ->where('role', 'client')
            ->where(function ($query) use ($phone, $normalized) {
                $query->where('whatsapp_phone', $phone)
                    ->orWhere('whatsapp_phone', $normalized);

                if (Schema::hasColumn('users', 'phone')) {
                    $query->orWhere('phone', $phone)
                        ->orWhere('phone', $normalized);
                }

                if ($normalized !== '') {
                    $query->orWhereRaw(
                        "regexp_replace(whatsapp_phone, '[^0-9]', '', 'g') = ?",
                        [$normalized]
                    );

                    if (Schema::hasColumn('users', 'phone')) {
                        $query->orWhereRaw(
                            "regexp_replace(phone, '[^0-9]', '', 'g') = ?",
                            [$normalized]
                        );
                    }
                }
            })
            ->first();
    }

    /**
     * Résout le client pour une vente POS : ID fourni, recherche par téléphone, ou création.
     */
    public static function resolveForSale(?int $clientId, ?string $phone, ?string $name): ?int
    {
        if ($clientId) {
            $client = User::query()
                ->where('role', 'client')
                ->find($clientId);

            return $client?->id;
        }

        $phone = $phone ? trim($phone) : null;
        $name = $name ? trim($name) : null;

        if (!$phone) {
            return null;
        }

        $existing = self::findExactByPhone($phone);

        if ($existing) {
            if ($name && $existing->name !== $name) {
                $existing->update(['name' => $name]);
            }

            return $existing->id;
        }

        if (!$name) {
            return null;
        }

        return self::createClient($name, $phone)->id;
    }

    public static function createClient(string $name, ?string $phone = null): User
    {
        $phone = $phone ? trim($phone) : null;

        return User::create([
            'name' => $name,
            'phone' => $phone,
            'whatsapp_phone' => $phone,
            'email' => 'pos_' . time() . '_' . Str::random(6) . '@afrikraga.local',
            'password' => bcrypt(Str::random(32)),
            'role' => 'client',
            'is_active' => true,
        ]);
    }

    public static function formatClient(User $client): array
    {
        $displayPhone = $client->phone ?: $client->whatsapp_phone;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone,
            'whatsapp_phone' => $client->whatsapp_phone,
            'display_phone' => $displayPhone,
            'email' => $client->email,
        ];
    }
}
