<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * Voir les images d'un produit
     * 
     * @param int $product_id - ID du produit
     * @return JsonResponse - Liste des images du produit
     */
    public function index(int $product_id): JsonResponse
    {
        try {
            // Récupérer le produit avec ses images
            $product = Product::with(['images' => function ($query) {
                    $query->orderBy('sort_order');
                }])
                ->find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier si le produit est actif
            if (!$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Séparer les images et les vidéos
            $images = $product->images->where('media_type', 'image');
            $videos = $product->images->where('media_type', 'video');

            // Formater les images
            $formattedImages = $images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'media_path' => asset('storage/' . $image->media_path),
                    'alt_text' => $image->alt_text,
                    'title' => $image->title,
                    'sort_order' => $image->sort_order,
                    'file_extension' => $image->file_extension,
                    'created_at' => $image->created_at
                ];
            });

            // Formater les vidéos
            $formattedVideos = $videos->map(function ($video) {
                return [
                    'id' => $video->id,
                    'media_path' => asset('storage/' . $video->media_path),
                    'alt_text' => $video->alt_text,
                    'title' => $video->title,
                    'sort_order' => $video->sort_order,
                    'file_extension' => $video->file_extension,
                    'created_at' => $video->created_at
                ];
            });

            // Formater la réponse
            $response = [
                'success' => true,
                'message' => 'Médias récupérés avec succès',
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'image_main' => $product->image_main ? asset('storage/' . $product->image_main) : null
                    ],
                    'images' => [
                        'data' => $formattedImages,
                        'count' => $formattedImages->count()
                    ],
                    'videos' => [
                        'data' => $formattedVideos,
                        'count' => $formattedVideos->count()
                    ],
                    'total_media' => $product->images->count(),
                    'has_image_main' => !empty($product->image_main)
                ]
            ];

            return response()->json($response, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des médias',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Ajouter une ou plusieurs images/vidéos à un produit (ADMIN ONLY)
     * 
     * @param Request $request - Fichiers média avec métadonnées
     * @param int $product_id - ID du produit
     * @return JsonResponse - Médias ajoutés
     */
    public function store(Request $request, int $product_id): JsonResponse
    {
        try {
            // Récupérer le produit
            $product = Product::find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier si le produit est actif
            if (!$product->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce produit n\'est pas disponible'
                ], 403);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'media_files.*' => 'required|file|mimes:jpeg,png,jpg,gif,webp,mp4,avi,mov,wmv,flv,webm|max:10240', // Max 10MB
                'alt_texts.*' => 'nullable|string|max:255',
                'titles.*' => 'nullable|string|max:255',
                'sort_orders.*' => 'nullable|integer|min:0'
            ], [
                'media_files.*.required' => 'Au moins un fichier média est requis',
                'media_files.*.file' => 'Le fichier doit être un fichier valide',
                'media_files.*.mimes' => 'Formats acceptés : jpeg, png, jpg, gif, webp, mp4, avi, mov, wmv, flv, webm',
                'media_files.*.max' => 'Chaque fichier ne peut pas dépasser 10MB',
                'alt_texts.*.max' => 'Le texte alternatif ne peut pas dépasser 255 caractères',
                'titles.*.max' => 'Le titre ne peut pas dépasser 255 caractères',
                'sort_orders.*.integer' => 'L\'ordre doit être un nombre entier',
                'sort_orders.*.min' => 'L\'ordre ne peut pas être négatif'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Récupérer les fichiers média
            $mediaFiles = $request->file('media_files');
            $altTexts = $request->input('alt_texts', []);
            $titles = $request->input('titles', []);
            $sortOrders = $request->input('sort_orders', []);

            // Vérifier qu'il y a au moins un fichier
            if (empty($mediaFiles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun fichier média fourni'
                ], 422);
            }

            $uploadedMedia = [];
            $errors = [];

            // Traiter chaque fichier
            foreach ($mediaFiles as $index => $file) {
                try {
                    // Déterminer le type de média
                    $mediaType = $this->determineMediaType($file);
                    
                    // Générer un nom de fichier unique
                    $fileName = 'product_' . $product_id . '_' . time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                    
                    // Stocker le fichier dans le dossier approprié
                    $folder = $mediaType === 'video' ? 'videos' : 'images';
                    $filePath = $file->storeAs("products/{$folder}", $fileName, 'public');

                    // Créer l'enregistrement en base de données
                    $media = ProductImage::create([
                        'product_id' => $product_id,
                        'media_path' => $filePath,
                        'media_type' => $mediaType,
                        'alt_text' => $altTexts[$index] ?? null,
                        'title' => $titles[$index] ?? null,
                        'sort_order' => $sortOrders[$index] ?? 0
                    ]);

                    // Formater la réponse pour ce média
                    $uploadedMedia[] = [
                        'id' => $media->id,
                        'media_path' => asset('storage/' . $media->media_path),
                        'media_type' => $media->media_type,
                        'alt_text' => $media->alt_text,
                        'title' => $media->title,
                        'sort_order' => $media->sort_order,
                        'file_extension' => $media->file_extension,
                        'created_at' => $media->created_at
                    ];

                } catch (\Exception $e) {
                    // En cas d'erreur, supprimer le fichier si il a été uploadé
                    if (isset($filePath) && Storage::disk('public')->exists($filePath)) {
                        Storage::disk('public')->delete($filePath);
                    }
                    
                    $errors[] = "Erreur lors de l'upload du fichier " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            // Si des erreurs sont survenues, retourner les erreurs
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreurs lors de l\'upload de certains fichiers',
                    'errors' => $errors,
                    'uploaded_media' => $uploadedMedia
                ], 422);
            }

            // Retourner la réponse de succès
            return response()->json([
                'success' => true,
                'message' => count($uploadedMedia) . ' média(x) ajouté(s) avec succès',
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug
                    ],
                    'uploaded_media' => $uploadedMedia,
                    'total_uploaded' => count($uploadedMedia)
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'ajout des médias',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Supprimer une image/vidéo d'un produit (ADMIN ONLY)
     * 
     * @param int $product_id - ID du produit
     * @param int $image_id - ID de l'image/vidéo à supprimer
     * @return JsonResponse - Message de confirmation
     */
    public function destroy(int $product_id, int $image_id): JsonResponse
    {
        try {
            // Récupérer le produit
            $product = Product::find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Récupérer le média à supprimer
            $media = ProductImage::where('product_id', $product_id)
                ->where('id', $image_id)
                ->first();

            // Vérifier si le média existe
            if (!$media) {
                return response()->json([
                    'success' => false,
                    'message' => 'Média non trouvé'
                ], 404);
            }

            // Supprimer le fichier physique
            if (Storage::disk('public')->exists($media->media_path)) {
                Storage::disk('public')->delete($media->media_path);
            }

            // Supprimer l'enregistrement en base de données
            $media->delete();

            return response()->json([
                'success' => true,
                'message' => 'Média supprimé avec succès',
                'data' => [
                    'deleted_media' => [
                        'id' => $media->id,
                        'media_type' => $media->media_type,
                        'file_path' => $media->media_path
                    ],
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du média',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Déterminer le type de média basé sur l'extension du fichier
     * 
     * @param \Illuminate\Http\UploadedFile $file - Fichier uploadé
     * @return string - Type de média ('image' ou 'video')
     */
    private function determineMediaType($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
        
        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $videoExtensions)) {
            return 'video';
        } else {
            // Par défaut, considérer comme image
            return 'image';
        }
    }

    /**
     * Mettre à jour l'ordre des médias d'un produit (ADMIN ONLY)
     * 
     * @param Request $request - Nouvel ordre des médias
     * @param int $product_id - ID du produit
     * @return JsonResponse - Ordre mis à jour
     */
    public function updateOrder(Request $request, int $product_id): JsonResponse
    {
        try {
            // Récupérer le produit
            $product = Product::find($product_id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'media_order' => 'required|array',
                'media_order.*.id' => 'required|exists:product_images,id',
                'media_order.*.sort_order' => 'required|integer|min:0'
            ], [
                'media_order.required' => 'L\'ordre des médias est requis',
                'media_order.array' => 'L\'ordre doit être un tableau',
                'media_order.*.id.required' => 'L\'ID du média est requis',
                'media_order.*.id.exists' => 'Un des médias n\'existe pas',
                'media_order.*.sort_order.required' => 'L\'ordre est requis',
                'media_order.*.sort_order.integer' => 'L\'ordre doit être un nombre entier',
                'media_order.*.sort_order.min' => 'L\'ordre ne peut pas être négatif'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Mettre à jour l'ordre de chaque média
            foreach ($request->media_order as $mediaOrder) {
                ProductImage::where('id', $mediaOrder['id'])
                    ->where('product_id', $product_id)
                    ->update(['sort_order' => $mediaOrder['sort_order']]);
            }

            // Récupérer les médias mis à jour
            $updatedMedia = ProductImage::where('product_id', $product_id)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'media_path' => asset('storage/' . $media->media_path),
                        'media_type' => $media->media_type,
                        'alt_text' => $media->alt_text,
                        'title' => $media->title,
                        'sort_order' => $media->sort_order
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Ordre des médias mis à jour avec succès',
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name
                    ],
                    'media' => $updatedMedia
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de l\'ordre',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }
}
