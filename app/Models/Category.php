<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_main',        // Image principale de la catégorie
        'parent_id',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    // Relation avec la catégorie parente
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    // Relation avec les sous-catégories
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // Relation avec les produits
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // Scope pour les catégories actives
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope pour les catégories principales (sans parent)
    public function scopeMain($query)
    {
        return $query->whereNull('parent_id');
    }

    // Scope pour les sous-catégories (avec parent)
    public function scopeSubcategories($query)
    {
        return $query->whereNotNull('parent_id');
    }

    // Méthode pour vérifier si c'est une catégorie principale
    public function isMain(): bool
    {
        return is_null($this->parent_id);
    }

    // Méthode pour vérifier si c'est une sous-catégorie
    public function isSubcategory(): bool
    {
        return !is_null($this->parent_id);
    }

    // Méthode pour obtenir le nombre de produits dans cette catégorie
    public function getProductsCountAttribute(): int
    {
        return $this->products()->count();
    }

    // Méthode pour obtenir le nombre de sous-catégories
    public function getSubcategoriesCountAttribute(): int
    {
        return $this->children()->count();
    }

    // Méthode pour obtenir l'URL complète de l'image
    public function getImageUrlAttribute(): string
    {
        if (!$this->image_main) {
            return '';
        }

        if (filter_var($this->image_main, FILTER_VALIDATE_URL)) {
            return $this->image_main;
        }
        
        return asset('storage/' . $this->image_main);
    }

    // Méthode pour obtenir le chemin complet de l'image
    public function getImagePathAttribute(): string
    {
        if (!$this->image_main) {
            return '';
        }

        if (filter_var($this->image_main, FILTER_VALIDATE_URL)) {
            return $this->image_main;
        }
        
        return storage_path('app/public/' . $this->image_main);
    }

    // Méthode pour vérifier si l'image existe
    public function hasImage(): bool
    {
        return !empty($this->image_main);
    }

    // Méthode pour obtenir le nom complet avec la hiérarchie
    public function getFullNameAttribute(): string
    {
        if ($this->isMain()) {
            return $this->name;
        }

        return $this->parent->name . ' > ' . $this->name;
    }

    // Méthode pour obtenir tous les ancêtres
    public function getAncestorsAttribute(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current) {
            $ancestors[] = $current;
            $current = $current->parent;
        }

        return array_reverse($ancestors);
    }

    // Méthode pour obtenir tous les descendants
    public function getAllDescendantsAttribute(): array
    {
        $descendants = [];
        $this->collectDescendants($descendants);
        return $descendants;
    }

    // Méthode récursive pour collecter tous les descendants
    private function collectDescendants(array &$descendants): void
    {
        foreach ($this->children as $child) {
            $descendants[] = $child;
            $child->collectDescendants($descendants);
        }
    }
}
