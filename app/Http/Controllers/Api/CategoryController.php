<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Afficher toutes les catégories avec leurs sous-catégories (ADMIN ONLY - y compris inactives)
     * 
     * @param Request $request - Requête avec filtres optionnels
     * @return JsonResponse - Liste des catégories organisées hiérarchiquement
     */
    public function indexAdmin(Request $request): JsonResponse
    {
        try {
            \Log::info('=== INDEX ADMIN DEBUG ===');
            \Log::info('Début de la méthode indexAdmin');
            
            // Vider le cache pour forcer le rechargement
            \Artisan::call('config:clear');
            \Artisan::call('cache:clear');
            
            // Récupérer toutes les catégories (y compris inactives) avec leurs relations
            $categories = Category::with(['children' => function ($query) {
                    $query->orderBy('sort_order');
                }])
                ->whereNull('parent_id') // Seulement les catégories principales
                ->orderBy('sort_order')
                ->get();

            \Log::info('Catégories récupérées:', ['count' => $categories->count()]);
            
            // Debug des statuts
            foreach ($categories as $cat) {
                \Log::info("Catégorie '{$cat->name}': is_active = " . ($cat->is_active ? 'true' : 'false') . " (type: " . gettype($cat->is_active) . ")");
            }

            // Formater les données pour inclure les URLs des images
            $formattedCategories = $categories->map(function ($category) {
                $productsCount = $category->children->sum(function ($subcategory) {
                    return $subcategory->products()->count();
                });
                
                \Log::info("Catégorie '{$category->name}': is_active = " . ($category->is_active ? 'true' : 'false') . ", products_count = {$productsCount}");
                
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'image_main' => $category->image_main && str_starts_with($category->image_main, 'http') ? $category->image_main : null,
                    'is_active' => (bool) $category->is_active, // Inclure le statut actif (forcé en booléen)
                    'has_subcategories' => $category->children->count() > 0,
                    'subcategories_count' => $category->children->count(),
                    'products_count' => $productsCount,
                    'subcategories' => $category->children->map(function ($subcategory) {
                        return [
                            'id' => $subcategory->id,
                            'name' => $subcategory->name,
                            'slug' => $subcategory->slug,
                            'description' => $subcategory->description,
                            'image_main' => $subcategory->image_main,
                            'is_active' => $subcategory->is_active, // Inclure le statut actif
                            'products_count' => $subcategory->products()->count(),
                            'created_at' => $subcategory->created_at,
                            'updated_at' => $subcategory->updated_at
                        ];
                    }),
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at
                ];
            });

            \Log::info('Catégories formatées avec succès');

            return response()->json([
                'success' => true,
                'message' => 'Catégories récupérées avec succès (Admin)',
                'data' => [
                    'categories' => $formattedCategories,
                    'total' => $categories->count()
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Erreur dans indexAdmin:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des catégories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Afficher toutes les catégories avec leurs sous-catégories
     * 
     * @param Request $request - Requête avec filtres optionnels
     * @return JsonResponse - Liste des catégories organisées hiérarchiquement
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Cache pour les catégories (cache long car elles changent rarement)
            $cacheKey = 'categories_index_active';
            
            // Cache désactivé temporairement
            $cachedResult = function () {
                // Récupérer toutes les catégories actives avec leurs relations
                $categories = Category::with(['children' => function ($query) {
                        $query->where('is_active', true)
                              ->orderBy('sort_order')
                              ->with(['children' => function ($subQuery) {
                                  $subQuery->where('is_active', true)
                                          ->orderBy('sort_order');
                              }]);
                    }])
                    ->whereNull('parent_id') // Seulement les catégories principales
                    ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            // Formater les données pour inclure les URLs des images
            $formattedCategories = $categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'image_main' => $category->image_main && str_starts_with($category->image_main, 'http') ? $category->image_main : null,
                    'has_subcategories' => $category->children->count() > 0,
                    'subcategories_count' => $category->children->count(),
                    'products_count' => $category->products()->count(),
                    'subcategories' => $category->children->map(function ($subcategory) {
                        return [
                            'id' => $subcategory->id,
                            'name' => $subcategory->name,
                            'slug' => $subcategory->slug,
                            'description' => $subcategory->description,
                            'image_main' => $subcategory->image_main,
                            'products_count' => $subcategory->products()->count(),
                            'created_at' => $subcategory->created_at,
                            'updated_at' => $subcategory->updated_at
                        ];
                    }),
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at
                ];
            });

                return [
                    'success' => true,
                    'message' => 'Catégories récupérées avec succès',
                    'data' => [
                        'categories' => $formattedCategories,
                        'total' => $categories->count()
                    ]
                ];
            };

            return response()->json($cachedResult(), 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des catégories',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Afficher une catégorie spécifique avec ses sous-catégories et produits
     * 
     * @param int $id - ID de la catégorie
     * @return JsonResponse - Détails de la catégorie avec ses relations
     */
    public function show(int $id): JsonResponse
    {
        try {
            // Récupérer la catégorie avec ses relations
            $category = Category::with([
                'children' => function ($query) {
                    $query->where('is_active', true)
                          ->orderBy('sort_order');
                },
                'products' => function ($query) {
                    $query->where('is_active', true)
                          ->with(['variants' => function ($variantQuery) {
                              $variantQuery->where('is_active', true);
                          }])
                          ->orderBy('sort_order')
                          ->limit(10); // Limiter à 10 produits pour éviter la surcharge
                }
            ])->find($id);

            // Vérifier si la catégorie existe
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Catégorie non trouvée'
                ], 404);
            }

            // Pour l'admin, on permet d'accéder à toutes les catégories (actives et inactives)
            // La vérification d'is_active se fait côté frontend selon les besoins

            // Formater les données de la catégorie
            $formattedCategory = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                                    'image_main' => $category->image_main && str_starts_with($category->image_main, 'http') ? $category->image_main : null,
                'is_active' => $category->is_active, // Inclure le statut actif
                'is_main_category' => $category->isMain(),
                'parent_category' => $category->parent ? [
                    'id' => $category->parent->id,
                    'name' => $category->parent->name,
                    'slug' => $category->parent->slug
                ] : null,
                'subcategories' => $category->children->map(function ($subcategory) {
                    return [
                        'id' => $subcategory->id,
                        'name' => $subcategory->name,
                        'slug' => $subcategory->slug,
                        'description' => $subcategory->description,
                        'image_main' => $subcategory->image_main,
                        'products_count' => $subcategory->products()->count(),
                        'created_at' => $subcategory->created_at
                    ];
                }),
                'products' => $category->products->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'description' => Str::limit($product->description, 100),
                        'base_price' => $product->base_price,
                        'image_main' => $product->image_main,
                        'has_variants' => $product->hasVariants(),
                        'variants_count' => $product->variants->count(),
                        'min_price' => $product->variants->count() > 0 ? $product->variants->min('price') : $product->base_price,
                        'created_at' => $product->created_at
                    ];
                }),
                'statistics' => [
                    'subcategories_count' => $category->children->count(),
                    'products_count' => $category->products->count(),
                    'total_products_including_subcategories' => $category->products->count() // Simplifié pour éviter l'erreur
                ],
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at
            ];

        return response()->json([
            'success' => true,
                'message' => 'Catégorie récupérée avec succès',
                'data' => $formattedCategory
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la catégorie',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Créer une nouvelle catégorie (ADMIN ONLY)
     * 
     * @param Request $request - Données de la catégorie avec image
     * @return JsonResponse - Catégorie créée
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // DEBUG: Log des données reçues
            \Log::info('=== CATEGORY STORE DEBUG ===');
            \Log::info('Données reçues:', $request->all());
            \Log::info('Content-Type: ' . $request->header('Content-Type'));
            \Log::info('Méthode: ' . $request->method());
            \Log::info('================================');

            // Validation des données de création
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'parent_id' => 'nullable|exists:categories,id', // ID de la catégorie parente (optionnel)
                'image_main' => 'nullable|string', // Image en base64
                'sort_order' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean'
            ], [
                'name.required' => 'Le nom de la catégorie est obligatoire',
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'parent_id.exists' => 'La catégorie parente sélectionnée n\'existe pas',
                'sort_order.integer' => 'L\'ordre doit être un nombre entier',
                'sort_order.min' => 'L\'ordre ne peut pas être négatif',
                'is_active.boolean' => 'Le statut actif doit être vrai ou faux'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Générer le slug unique à partir du nom
            $slug = Str::slug($request->name);
            $originalSlug = $slug;
            $counter = 1;

            // Vérifier l'unicité du slug
            while (Category::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Traitement de l'image si fournie (base64)
            $imageUrl = null;
            if ($request->filled('image_main') && is_string($request->image_main)) {
                try {
                    // Vérifier que c'est bien une image base64
                    if (strpos($request->image_main, 'data:image/') === 0) {
                        // Créer un fichier temporaire pour Cloudinary
                        $base64Data = $request->image_main;
                        $imageData = base64_decode(explode(',', $base64Data)[1]);
                        
                        // Déterminer l'extension à partir du type MIME
                        $mimeType = explode(';', explode(',', $base64Data)[0])[0];
                        $extension = str_replace('data:image/', '', $mimeType);
                        
                        // Générer un nom de fichier unique
                        $fileName = 'category_' . time() . '_' . Str::random(10) . '.' . $extension;
                        
                        // Créer un fichier temporaire
                        $tempFile = tmpfile();
                        fwrite($tempFile, $imageData);
                        $tempPath = stream_get_meta_data($tempFile)['uri'];
                        
                        // Créer un UploadedFile pour Cloudinary
                        $uploadedFile = new \Illuminate\Http\UploadedFile(
                            $tempPath,
                            $fileName,
                            $mimeType,
                            null,
                            true
                        );
                        
                        // Upload vers Cloudinary
                        $cloudinaryService = new CloudinaryService();
                        $result = $cloudinaryService->uploadImage($uploadedFile, 'bs_shop/categories');
                        
                        if ($result['success']) {
                            $imageUrl = $result['secure_url'];
                            \Log::info('Image uploadée vers Cloudinary:', ['url' => $imageUrl]);
                        } else {
                            \Log::error('Erreur upload Cloudinary:', ['error' => $result['error']]);
                        }
                        
                        // Fermer le fichier temporaire
                        fclose($tempFile);
                    }
                } catch (\Exception $e) {
                    \Log::error('Erreur lors du traitement de l\'image base64:', ['error' => $e->getMessage()]);
                    // Continuer sans l'image si le traitement échoue
                }
            }

                        // Créer la catégorie
            $category = Category::create([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description ?? null,
                'image_main' => $imageUrl,
                'parent_id' => $request->parent_id,
                'sort_order' => $request->sort_order ?? 0,
                'is_active' => $request->is_active ?? true
            ]);

            // Charger les relations pour la réponse
            $category->load(['parent', 'children']);

            // Formater la réponse
            $formattedCategory = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                                    'image_main' => $category->image_main && str_starts_with($category->image_main, 'http') ? $category->image_main : null,
                'parent_category' => $category->parent ? [
                    'id' => $category->parent->id,
                    'name' => $category->parent->name,
                    'slug' => $category->parent->slug
                ] : null,
                'is_main_category' => $category->isMain(),
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Catégorie créée avec succès',
                'data' => $formattedCategory
            ], 201);

        } catch (\Exception $e) {
            // En cas d'erreur, supprimer l'image si elle a été uploadée
            if (isset($imagePath) && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la catégorie',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Mettre à jour une catégorie existante (ADMIN ONLY)
     * 
     * @param Request $request - Nouvelles données de la catégorie
     * @param int $id - ID de la catégorie à modifier
     * @return JsonResponse - Catégorie mise à jour
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer la catégorie à modifier
            $category = Category::find($id);

            // Vérifier si la catégorie existe
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Catégorie non trouvée'
                ], 404);
            }

            // Validation des données de mise à jour
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'parent_id' => 'sometimes|nullable|integer|exists:categories,id',
                'image_main' => 'sometimes|string', // Image en base64
                'sort_order' => 'sometimes|integer|min:0',
                'is_active' => 'sometimes|boolean'
            ], [
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'parent_id.exists' => 'La catégorie parente sélectionnée n\'existe pas',
                'parent_id.integer' => 'L\'ID de la catégorie parente doit être un nombre',
                'sort_order.integer' => 'L\'ordre doit être un nombre entier',
                'sort_order.min' => 'L\'ordre ne peut pas être négatif'
            ]);

            // Si validation échoue, retourner les erreurs
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Vérifier qu'on ne crée pas de boucle hiérarchique
            if ($request->has('parent_id') && $request->parent_id == $id) {
        return response()->json([
                    'success' => false,
                    'message' => 'Une catégorie ne peut pas être sa propre parente'
                ], 422);
            }

            // Traitement de la nouvelle image si fournie (base64)
            $oldImagePath = $category->image_main;
            $imagePath = $oldImagePath;

            if ($request->filled('image_main') && is_string($request->image_main)) {
                try {
                    // Vérifier que c'est bien une image base64
                    if (strpos($request->image_main, 'data:image/') === 0) {
                        // Extraire les données base64
                        $base64Data = $request->image_main;
                        $imageData = base64_decode(explode(',', $base64Data)[1]);
                        
                        // Déterminer l'extension à partir du type MIME
                        $mimeType = explode(';', explode(',', $base64Data)[0])[0];
                        $extension = str_replace('data:image/', '', $mimeType);
                        
                        // Générer un nom de fichier unique
                        $fileName = 'category_' . time() . '_' . Str::random(10) . '.' . $extension;
                        
                        // Stocker la nouvelle image
                        $imagePath = 'categories/' . $fileName;
                        Storage::disk('public')->put($imagePath, $imageData);
                        
                        // Supprimer l'ancienne image si elle existe
                        if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                            Storage::disk('public')->delete($oldImagePath);
                        }
                        
                        \Log::info('Image base64 mise à jour:', ['path' => $imagePath, 'size' => strlen($imageData)]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Erreur lors de la mise à jour de l\'image base64:', ['error' => $e->getMessage()]);
                    // Continuer avec l'ancienne image si le traitement échoue
                }
            }

            // Mettre à jour les champs fournis
            if ($request->has('name')) {
                $category->name = $request->name;
                // Régénérer le slug si le nom change
                $slug = Str::slug($request->name);
                $originalSlug = $slug;
                $counter = 1;

                while (Category::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                    $slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
                $category->slug = $slug;
            }

            if ($request->has('description')) {
                $category->description = $request->description;
            }

            if ($request->has('parent_id')) {
                $category->parent_id = $request->parent_id;
            }

            if ($request->has('sort_order')) {
                $category->sort_order = $request->sort_order;
            }

            if ($request->has('is_active')) {
                $category->is_active = $request->is_active;
            }

            // Mettre à jour l'image
            $category->image_main = $imagePath;

            // Sauvegarder les modifications
            $category->save();

            // Charger les relations pour la réponse
            $category->load(['parent', 'children']);

            // Formater la réponse
            $formattedCategory = [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                                    'image_main' => $category->image_main && str_starts_with($category->image_main, 'http') ? $category->image_main : null,
                'parent_category' => $category->parent ? [
                    'id' => $category->parent->id,
                    'name' => $category->parent->name,
                    'slug' => $category->parent->slug
                ] : null,
                'is_main_category' => $category->isMain(),
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
                'updated_at' => $category->updated_at
        ];

        return response()->json([
            'success' => true,
                'message' => 'Catégorie mise à jour avec succès',
                'data' => $formattedCategory
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la catégorie',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Supprimer une catégorie (ADMIN ONLY)
     * 
     * @param int $id - ID de la catégorie à supprimer
     * @return JsonResponse - Message de confirmation
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Récupérer la catégorie à supprimer
            $category = Category::with(['children', 'products'])->find($id);

            // Vérifier si la catégorie existe
            if (!$category) {
        return response()->json([
                    'success' => false,
                    'message' => 'Catégorie non trouvée'
                ], 404);
            }

            // Vérifier s'il y a des produits dans cette catégorie
            if ($category->products->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer cette catégorie',
                    'error' => 'Cette catégorie contient des produits. Supprimez d\'abord tous les produits.'
                ], 422);
            }

            // Vérifier s'il y a des sous-catégories
            if ($category->children->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer cette catégorie',
                    'error' => 'Cette catégorie contient des sous-catégories. Supprimez d\'abord toutes les sous-catégories.'
                ], 422);
            }

            // Supprimer l'image associée si elle existe
            if ($category->image_main && Storage::disk('public')->exists($category->image_main)) {
                Storage::disk('public')->delete($category->image_main);
            }

            // Supprimer la catégorie
            $category->delete();

        return response()->json([
            'success' => true,
                'message' => 'Catégorie supprimée avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la catégorie',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Upload d'image pour une catégorie (ADMIN ONLY)
     * 
     * @param Request $request - Fichier image
     * @param int $id - ID de la catégorie
     * @return JsonResponse - Image uploadée
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer la catégorie
            $category = Category::find($id);
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Catégorie non trouvée'
                ], 404);
            }

            // Validation du fichier image
            $validator = Validator::make($request->all(), [
                'image_main' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048'
            ], [
                'image_main.required' => 'L\'image est obligatoire',
                'image_main.image' => 'Le fichier doit être une image',
                'image_main.mimes' => 'Formats d\'image acceptés : jpeg, png, jpg, gif, webp',
                'image_main.max' => 'L\'image ne peut pas dépasser 2MB'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Uploader la nouvelle image vers Cloudinary
            $cloudinaryService = new CloudinaryService();
            $image = $request->file('image_main');
            $uploadResult = $cloudinaryService->uploadImage($image, 'bs_shop/categories');

            if (!$uploadResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'upload de l\'image',
                    'error' => $uploadResult['error']
                ], 500);
            }

            // Mettre à jour la catégorie
            $category->image_main = $uploadResult['secure_url'];
            $category->save();

            return response()->json([
                'success' => true,
                'message' => 'Image uploadée avec succès',
                'data' => [
                    'image_url' => $uploadResult['secure_url'],
                    'image_path' => $uploadResult['public_id']
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'upload de l\'image',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

}
