<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartSession extends Model
{
    protected $fillable = [
        'session_id',
        'client_id',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime'
    ];

    // Relation avec le client (optionnel)
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relation avec les éléments du panier
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // Méthode pour vérifier si la session est expirée
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    // Méthode pour calculer le total du panier
    public function getTotalAttribute(): float
    {
        return $this->items->sum(function ($item) {
            $price = $item->variant ? $item->variant->price : $item->product->base_price;
            return $price * $item->quantity;
        });
    }

    // Méthode pour obtenir le nombre d'articles
    public function getItemCountAttribute(): int
    {
        return $this->items->sum('quantity');
    }

    // Méthode pour nettoyer les sessions expirées
    public static function cleanExpired(): void
    {
        static::where('expires_at', '<', now())->delete();
    }
}
