<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_session_id',
        'product_id',
        'product_variant_id',
        'quantity'
    ];

    protected $casts = [
        'quantity' => 'integer'
    ];

    // Relation avec la session de panier
    public function cartSession(): BelongsTo
    {
        return $this->belongsTo(CartSession::class);
    }

    // Relation avec le produit
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Relation avec la variante (optionnel)
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    // Méthode pour obtenir le prix unitaire
    public function getUnitPriceAttribute(): float
    {
        if ($this->variant) {
            return $this->variant->price;
        }
        return $this->product->base_price ?? 0;
    }

    // Méthode pour calculer le prix total
    public function getTotalPriceAttribute(): float
    {
        return $this->unit_price * $this->quantity;
    }

    // Méthode pour mettre à jour la quantité
    public function updateQuantity(int $quantity): void
    {
        $this->quantity = max(1, $quantity);
        $this->save();
    }
}
