<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'sku',               // Code SKU unique de la variante
        'price',
        'stock_quantity',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    // Relation avec le produit
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Relation avec les commandes
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Relation avec les éléments du panier
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // Scope pour les variantes actives
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope pour les variantes en stock
    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    // Scope pour les variantes en rupture de stock
    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', 0);
    }

    // Scope pour les variantes en stock faible
    public function scopeLowStock($query, $threshold = 5)
    {
        return $query->where('stock_quantity', '<=', $threshold);
    }

    // Méthode pour vérifier la disponibilité
    public function isAvailable(): bool
    {
        if (is_null($this->stock_quantity)) {
            return true; // Stock illimité
        }
        // 0 signifie stock illimité, > 0 signifie stock limité
        return $this->stock_quantity >= 0;
    }

    // Méthode pour obtenir le nom formaté avec SKU
    public function getFormattedNameAttribute(): string
    {
        if ($this->sku) {
            return $this->name . ' (' . $this->sku . ')';
        }
        return $this->name;
    }

    // Méthode pour vérifier si la variante a un SKU
    public function hasSku(): bool
    {
        return !empty($this->sku);
    }
}
