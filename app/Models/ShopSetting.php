<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopSetting extends Model
{
    public const PAYMENT_ESPECES = 'especes';

    public const PAYMENT_ORANGE_MONEY = 'orange_money';

    public const PAYMENT_WAVE = 'wave';

    public const PAYMENT_DEPOT = 'depot';

    public const PAYMENT_CARTE = 'carte';

    /**
     * @return list<string>
     */
    public static function paymentMethodKeys(): array
    {
        return [
            self::PAYMENT_ESPECES,
            self::PAYMENT_ORANGE_MONEY,
            self::PAYMENT_WAVE,
            self::PAYMENT_DEPOT,
            self::PAYMENT_CARTE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMethodLabels(): array
    {
        return [
            self::PAYMENT_ESPECES => 'Espèces',
            self::PAYMENT_ORANGE_MONEY => 'Orange Money',
            self::PAYMENT_WAVE => 'Wave',
            self::PAYMENT_DEPOT => 'Dépôt / agence',
            self::PAYMENT_CARTE => 'Carte',
        ];
    }

    /**
     * Champs modifiables par admin et gérant.
     *
     * @return list<string>
     */
    public static function operationalKeys(): array
    {
        return [
            'preorder_delay_days',
            'unpaid_expiry_hours',
            'low_stock_threshold',
            'min_deposit_percent',
        ];
    }

    /**
     * Champs réservés à l'administrateur.
     *
     * @return list<string>
     */
    public static function identityKeys(): array
    {
        return [
            'payment_methods',
            'whatsapp_number',
            'notify_whatsapp',
            'notify_email',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'preorder_delay_days' => 14,
            'unpaid_expiry_hours' => 24,
            'low_stock_threshold' => 5,
            'min_deposit_percent' => 0,
            'payment_methods' => self::paymentMethodKeys(),
            'whatsapp_number' => '+22663126849',
            'notify_whatsapp' => true,
            'notify_email' => true,
        ];
    }

    protected $fillable = [
        'preorder_delay_days',
        'unpaid_expiry_hours',
        'low_stock_threshold',
        'min_deposit_percent',
        'payment_methods',
        'whatsapp_number',
        'notify_whatsapp',
        'notify_email',
    ];

    protected function casts(): array
    {
        return [
            'preorder_delay_days' => 'integer',
            'unpaid_expiry_hours' => 'integer',
            'low_stock_threshold' => 'integer',
            'min_deposit_percent' => 'integer',
            'payment_methods' => 'array',
            'notify_whatsapp' => 'boolean',
            'notify_email' => 'boolean',
        ];
    }

    public static function current(): self
    {
        $row = static::query()->first();

        if ($row) {
            return $row;
        }

        return static::query()->create(static::defaults());
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'preorder_delay_days' => $this->preorder_delay_days,
            'unpaid_expiry_hours' => $this->unpaid_expiry_hours,
            'low_stock_threshold' => $this->low_stock_threshold,
            'min_deposit_percent' => $this->min_deposit_percent,
            'payment_methods' => array_values($this->payment_methods ?? []),
            'whatsapp_number' => $this->whatsapp_number,
            'notify_whatsapp' => (bool) $this->notify_whatsapp,
            'notify_email' => (bool) $this->notify_email,
        ];
    }
}
