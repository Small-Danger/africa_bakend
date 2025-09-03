<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'base_price',
        'category_id',
        'image_main',        // Image principale du produit
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    // Relation avec la catégorie
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Relation avec les variantes
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    // Relation avec les images/médias
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    // Relation avec les images uniquement
    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('media_type', 'image');
    }

    // Relation avec les vidéos uniquement
    public function videos(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('media_type', 'video');
    }

    // Scope pour les produits actifs
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Méthode pour obtenir le prix (base ou variante)
    public function getPriceAttribute()
    {
        if ($this->variants()->exists()) {
            return $this->variants()->min('price');
        }
        return $this->base_price;
    }

    // Méthode pour vérifier si le produit a des variantes
    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }

    // Méthode pour obtenir l'image principale (depuis image_main ou la première image)
    public function getMainImageAttribute()
    {
        if ($this->image_main) {
            return $this->image_main;
        }
        
        return $this->images()->where('media_type', 'image')->orderBy('sort_order')->first()?->media_path;
    }

    // Méthode pour obtenir toutes les images du produit
    public function getAllImagesAttribute()
    {
        return $this->images()->where('media_type', 'image')->orderBy('sort_order')->get();
    }

    // Méthode pour obtenir toutes les vidéos du produit
    public function getAllVideosAttribute()
    {
        return $this->images()->where('media_type', 'video')->orderBy('sort_order')->get();
    }
}
