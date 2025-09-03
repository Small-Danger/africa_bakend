<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
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
            // Upload du fichier
            $imageFile = $request->file('image');
            $imageName = time() . '_' . $imageFile->getClientOriginalName();
            $imagePath = $imageFile->storeAs('products', $imageName, 'public');

            // Si c'est l'image principale, désactiver les autres
            if ($request->is_main) {
                $product->images()->where('is_main', true)->update(['is_main' => false]);
            }

            $image = $product->images()->create([
                'image_path' => $imagePath,
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
            // Supprimer le fichier physique
            if (Storage::disk('public')->exists($image->image_path)) {
                Storage::disk('public')->delete($image->image_path);
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
