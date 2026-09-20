<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPreorder extends Model
{
    public const WAITING = 'waiting';

    public const ALLOCATED = 'allocated';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id',
        'product_variant_id',
        'quantity',
        'original_quantity',
        'status',
        'expires_at',
        'allocated_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'original_quantity' => 'integer',
            'expires_at' => 'datetime',
            'allocated_at' => 'datetime',
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

    public function scopeWaiting($query)
    {
        return $query->where('status', self::WAITING);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }
}
