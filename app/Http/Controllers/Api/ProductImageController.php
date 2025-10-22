<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    /**
     * [ADMIN] Ajouter une ou plusieurs images à un produit
     */
    public function store(Request $request, string $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            'is_main' => 'boolean',
            'sort_order' => 'integer'
        ]);

        try {
            // Upload du fichier vers Cloudinary
            $imageFile = $request->file('image');
            $cloudinaryService = new CloudinaryService();
            $uploadResult = $cloudinaryService->uploadImage($imageFile, 'bs_shop/products/images');

            if (!$uploadResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'upload de l\'image',
                    'error' => $uploadResult['error']
                ], 500);
            }

            // Si c'est l'image principale, désactiver les autres
            if ($request->is_main) {
                $product->images()->where('is_main', true)->update(['is_main' => false]);
            }

            $image = $product->images()->create([
                'media_path' => $uploadResult['secure_url'],
                'media_type' => 'image',
                'is_main' => $request->is_main ?? false,
                'sort_order' => $request->sort_order ?? 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Image ajoutée avec succès',
                'data' => $image
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'upload de l\'image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [ADMIN] Supprimer une image d'un produit
     */
    public function destroy(string $productId, string $imageId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $image = $product->images()->findOrFail($imageId);

        try {
            // Supprimer l'image de Cloudinary si elle existe
            if ($image->media_path) {
                $cloudinaryService = new CloudinaryService();
                $cloudinaryService->deleteImage($image->media_path);
            }

            // Supprimer l'enregistrement de la base de données
            $image->delete();

            return response()->json([
                'success' => true,
                'message' => 'Image supprimée avec succès'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de l\'image: ' . $e->getMessage()
            ], 500);
        }
    }
}
