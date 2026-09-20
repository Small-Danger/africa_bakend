<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    public const ACTIVE = 'active';

    public const CONFIRMED = 'confirmed';

    public const RELEASED = 'released';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'order_id',
        'product_variant_id',
        'quantity',
        'status',
        'expires_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
