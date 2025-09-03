<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'media_path',        // Chemin vers le fichier média (image ou vidéo)
        'media_type',        // Type de média (image ou video)
        'alt_text',          // Texte alternatif pour l'accessibilité
        'title',             // Titre du média (tooltip)
        'sort_order'         // Ordre d'affichage des médias
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];

    // Constantes pour les types de média
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';

    // Relation avec le produit
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Relation avec les variantes (many-to-many)
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_variant_images')
                    ->withTimestamps();
    }

    // Scope pour les images uniquement
    public function scopeImages($query)
    {
        return $query->where('media_type', self::TYPE_IMAGE);
    }

    // Scope pour les vidéos uniquement
    public function scopeVideos($query)
    {
        return $query->where('media_type', self::TYPE_VIDEO);
    }

    // Scope pour un type de média spécifique
    public function scopeOfType($query, string $type)
    {
        return $query->where('media_type', $type);
    }

    // Méthode pour vérifier si c'est une image
    public function isImage(): bool
    {
        return $this->media_type === self::TYPE_IMAGE;
    }

    // Méthode pour vérifier si c'est une vidéo
    public function isVideo(): bool
    {
        return $this->media_type === self::TYPE_VIDEO;
    }

    // Méthode pour obtenir l'URL complète du média
    public function getFullUrlAttribute(): string
    {
        if (filter_var($this->media_path, FILTER_VALIDATE_URL)) {
            return $this->media_path;
        }
        
        return asset('storage/' . $this->media_path);
    }

    // Méthode pour obtenir l'extension du fichier
    public function getFileExtensionAttribute(): string
    {
        return pathinfo($this->media_path, PATHINFO_EXTENSION);
    }

    // Méthode pour vérifier si c'est un fichier vidéo
    public function isVideoFile(): bool
    {
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
        return in_array(strtolower($this->file_extension), $videoExtensions);
    }

    // Méthode pour vérifier si c'est un fichier image
    public function isImageFile(): bool
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        return in_array(strtolower($this->file_extension), $imageExtensions);
    }
}
