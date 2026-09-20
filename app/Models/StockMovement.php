<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $fillable = [
        'product_variant_id',
        'quantity',
        'quantity_after',
        'type',
        'channel',
        'reason',
        'user_id',
        'reference_type',
        'reference_id',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_after' => 'integer',
            'properties' => 'array',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
