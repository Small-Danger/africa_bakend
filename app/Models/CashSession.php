<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    protected $fillable = [
        'cashier_id',
        'opening_amount',
        'closing_amount_expected',
        'closing_amount_counted',
        'discrepancy',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'closing_amount_expected' => 'decimal:2',
        'closing_amount_counted' => 'decimal:2',
        'discrepancy' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isOpen(): bool
    {
        return is_null($this->closed_at);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }
}
