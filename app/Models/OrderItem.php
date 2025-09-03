<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'total_price'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2'
    ];

    // Relation avec la commande
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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

    // Méthode pour calculer le prix total
    public function calculateTotal(): void
    {
        $this->total_price = $this->quantity * $this->unit_price;
        $this->save();
    }

    // Méthode pour obtenir le nom complet du produit
    public function getProductNameAttribute(): string
    {
        $name = $this->product->name;
        if ($this->variant) {
            $name .= ' - ' . $this->variant->name;
        }
        return $name;
    }
}
