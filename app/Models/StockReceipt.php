<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReceipt extends Model
{
    protected $fillable = [
        'user_id',
        'received_at',
        'note',
        'merchandise_cost',
        'shipping_cost',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'merchandise_cost' => 'integer',
            'shipping_cost' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function invested(): int
    {
        return (int) $this->merchandise_cost + (int) $this->shipping_cost;
    }
}
