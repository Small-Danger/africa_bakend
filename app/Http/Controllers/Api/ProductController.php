<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Liste des produits avec filtres (catégorie, sous-catégorie, mot-clé, prix)
     * 
     * @param Request $request - Requête avec filtres optionnels
     * @return JsonResponse - Liste des produits filtrés et paginés
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Créer une clé de cache basée sur les paramètres de la requête
            $cacheKey = 'products_index_' . md5(serialize($request->all()));
            
            // Vérifier le cache d'abord
            $cachedResult = Cache::remember($cacheKey, 300, function () use ($request) {
                // Construire la requête de base
                $query = Product::with(['category' => function ($categoryQuery) {
                        $categoryQuery->with('parent'); // Charger aussi la catégorie parente
                    }, 'variants' => function ($variantQuery) {
                        $variantQuery->where('is_active', true);
                    }])
                    ->where('is_active', true);

            // Filtre par catégorie principale
            if ($request->has('category_id') && $request->category_id) {
                $query->where('category_id', $request->category_id);
            }

            // Filtre par sous-catégorie (catégories enfants)
            if ($request->has('subcategory_id') && $request->subcategory_id) {
                $query->where('category_id', $request->subcategory_id);
            }

            // Filtre par mot-clé (recherche dans le nom et la description)
            if ($request->has('search') && $request->search) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'LIKE', "%{$searchTerm}%")
                      ->orWhere('description', 'LIKE', "%{$searchTerm}%");
                });
            }

            // Filtre par prix minimum
            if ($request->has('min_price') && $request->min_price) {
                $query->where(function ($q) use ($request) {
                    $q->where('base_price', '>=', $request->min_price)
                      ->orWhereHas('variants', function ($variantQ) use ($request) {
                          $variantQ->where('price', '>=', $request->min_price);
                      });
                });
            }

            // Filtre par prix maximum
            if ($request->has('max_price') && $request->max_price) {
                $query->where(function ($q) use ($request) {
                    $q->where('base_price', '<=', $request->max_price)
                      ->orWhereHas('variants', function ($variantQ) use ($request) {
                          $variantQ->where('price', '<=', $request->max_price);
                      });
                });
            }

            // Tri des produits
            $sortBy = $request->get('sort_by', 'sort_order');
            $sortOrder = $request->get('sort_order', 'asc');
            
            if ($sortBy === 'price') {
                // Tri par prix (base_price ou prix minimum des variantes)
                $query->orderBy('base_price', $sortOrder);
            } elseif ($sortBy === 'name') {
                $query->orderBy('name', $sortOrder);
            } else {
                $query->orderBy('sort_order', $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 12);
            $products = $query->paginate($perPage);

            // Formater les données des produits
            $formattedProducts = $products->getCollection()->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => Str::limit($product->description, 150),
                    'base_price' => $product->base_price,
                    'image_main' => $product->image_main ? asset('storage/' . $product->image_main) : null,
                    'category' => [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'slug' => $product->category->slug,
                        'is_main' => $product->category->isMain(),
                        'is_subcategory' => $product->category->isSubcategory(),
                        'parent' => $product->category->parent ? [
                            'id' => $product->category->parent->id,
                            'name' => $product->category->parent->name,
                            'slug' => $product->category->parent->slug
                        ] : null
                    ],
                    'has_variants' => $product->hasVariants(),
                    'variants_count' => $product->variants->count(),
                    'min_price' => $product->variants->count() > 0 ? $product->variants->min('price') : $product->base_price,
                    'max_price' => $product->variants->count() > 0 ? $product->variants->max('price') : $product->base_price,
                    'sort_order' => $product->sort_order,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at
                ];
            });

                // Formater la réponse avec pagination
                return [
                    'success' => true,
                    'message' => 'Produits récupérés avec succès',
                    'data' => [
                        'products' => $formattedProducts,
                        'pagination' => [
                            'current_page' => $products->currentPage(),
                            'last_page' => $products->lastPage(),
                            'per_page' => $products->perPage(),
                            'total' => $products->total(),
                            'from' => $products->firstItem(),
                            'to' => $products->lastItem()
                        ]
                    ]
                ];
            });

            return response()->json($cachedResult, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Afficher un produit spécifique avec ses variantes et images
     * 
     * @param int $id - ID du produit
     * @return JsonResponse - Détails complets du produit
     */
    public function show(int $id): JsonResponse
    {
        try {
            // Cache pour un produit spécifique
            $cacheKey = "product_show_{$id}";
            
            $cachedResult = Cache::remember($cacheKey, 600, function () use ($id) {
                // Récupérer le produit avec toutes ses relations
                $product = Product::with([
                    'category' => function ($query) {
                        $query->with('parent'); // Charger aussi la catégorie parente
                    },
                    'variants' => function ($query) {
                        $query->where('is_active', true)
                              ->orderBy('sort_order');
                    },
                    'images' => function ($query) {
                        $query->orderBy('sort_order');
                    }
                ])->find($id);

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

            // Formater les données du produit
            $formattedProduct = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'base_price' => $product->base_price,
                'image_main' => $product->image_main ? asset('storage/' . $product->image_main) : null,
                'category' => [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                    'description' => $product->category->description,
                    'is_main' => $product->category->isMain(),
                    'is_subcategory' => $product->category->isSubcategory(),
                    'parent' => $product->category->parent ? [
                        'id' => $product->category->parent->id,
                        'name' => $product->category->parent->name,
                        'slug' => $product->category->parent->slug,
                        'description' => $product->category->parent->description
                    ] : null
                ],
                'has_variants' => $product->hasVariants(),
                'variants' => $product->variants->map(function ($variant) {
                    return [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'sku' => $variant->sku,
                        'price' => $variant->price,
                        'stock_quantity' => $variant->stock_quantity,
                        'is_available' => $variant->isAvailable(),
                        'sort_order' => $variant->sort_order
                    ];
                }),
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'media_path' => asset('storage/' . $image->media_path),
                        'media_type' => $image->media_type,
                        'alt_text' => $image->alt_text,
                        'title' => $image->title,
                        'sort_order' => $image->sort_order
                    ];
                }),
                'videos' => $product->videos->map(function ($video) {
                    return [
                        'id' => $video->id,
                        'media_path' => asset('storage/' . $video->media_path),
                        'alt_text' => $video->alt_text,
                        'title' => $video->title,
                        'sort_order' => $video->sort_order
                    ];
                }),
                'pricing' => [
                    'base_price' => $product->base_price,
                    'min_price' => $product->variants->count() > 0 ? $product->variants->min('price') : $product->base_price,
                    'max_price' => $product->variants->count() > 0 ? $product->variants->max('price') : $product->base_price,
                    'has_price_range' => $product->variants->count() > 0
                ],
                'stock_info' => [
                    'total_variants' => $product->variants->count(),
                    'available_variants' => $product->variants->where('stock_quantity', '>', 0)->count(),
                    'unlimited_stock_variants' => $product->variants->whereNull('stock_quantity')->count()
                ],
                'sort_order' => $product->sort_order,
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at
            ];

                return [
                    'success' => true,
                    'message' => 'Produit récupéré avec succès',
                    'data' => $formattedProduct
                ];
            });

            return response()->json($cachedResult, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du produit',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Créer un nouveau produit (ADMIN ONLY) - Support base64 et FormData
     * 
     * @param Request $request - Données du produit avec images (base64 ou fichiers)
     * @return JsonResponse - Produit créé
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validation des données de création
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'required|string',
                'base_price' => 'nullable|numeric|min:0',
                'category_id' => 'required|exists:categories,id',
                'image_main' => 'nullable', // Peut être base64 ou fichier
                'image_main_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048', // Pour compatibilité FormData
                'sort_order' => 'nullable|integer|min:0',
                'images' => 'nullable|array', // Tableau d'images base64
                'images.*.data' => 'nullable|string', // Données base64
                'images.*.alt_text' => 'nullable|string|max:255',
                'images.*.title' => 'nullable|string|max:255',
                'images.*.sort_order' => 'nullable|integer|min:0'
            ], [
                'name.required' => 'Le nom du produit est obligatoire',
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'description.required' => 'La description du produit est obligatoire',
                'base_price.numeric' => 'Le prix de base doit être un nombre',
                'base_price.min' => 'Le prix de base ne peut pas être négatif',
                'category_id.required' => 'La catégorie est obligatoire',
                'category_id.exists' => 'La catégorie sélectionnée n\'existe pas',
                'image_main_file.image' => 'Le fichier doit être une image',
                'image_main_file.mimes' => 'Formats d\'image acceptés : jpeg, png, jpg, gif, webp',
                'image_main_file.max' => 'L\'image ne peut pas dépasser 2MB',
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

            // Vérifier que la catégorie existe et est active
            $category = Category::find($request->category_id);
            if (!$category || !$category->is_active) {
        return response()->json([
                    'success' => false,
                    'message' => 'La catégorie sélectionnée n\'est pas disponible'
                ], 422);
            }

            // Générer le slug unique à partir du nom
            $slug = Str::slug($request->name);
            $originalSlug = $slug;
            $counter = 1;

            // Vérifier l'unicité du slug
            while (Product::where('slug', $slug)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            // Traitement de l'image principale
            $imagePath = null;
            
            // Vérifier si c'est une image base64
            if ($request->has('image_main') && $request->image_main && is_string($request->image_main)) {
                $imagePath = $this->saveBase64Image($request->image_main, 'products');
            }
            // Vérifier si c'est un fichier uploadé (compatibilité FormData)
            elseif ($request->hasFile('image_main_file')) {
                $image = $request->file('image_main_file');
                $fileName = 'product_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('products', $fileName, 'public');
            }

            // Créer le produit
            $product = Product::create([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'base_price' => $request->base_price,
                'category_id' => $request->category_id,
                'image_main' => $imagePath,
                'sort_order' => $request->sort_order ?? 0,
                'is_active' => true
            ]);

            // Traitement des images supplémentaires en base64
            if ($request->has('images') && is_array($request->images)) {
                foreach ($request->images as $imageData) {
                    if (isset($imageData['data']) && $imageData['data']) {
                        $this->saveProductImageFromBase64($product, $imageData);
                    }
                }
            }

            // Invalider le cache des produits
            Cache::forget('products_index_' . md5(serialize([])));
            Cache::forget("product_show_{$product->id}");

            // Charger les relations pour la réponse
            $product->load(['category', 'variants', 'images']);

            // Formater la réponse
            $formattedProduct = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'base_price' => $product->base_price,
                'image_main' => $product->image_main ? asset('storage/' . $product->image_main) : null,
                'category' => [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug
                ],
                'has_variants' => $product->hasVariants(),
                'variants_count' => $product->variants->count(),
                'sort_order' => $product->sort_order,
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Produit créé avec succès',
                'data' => $formattedProduct
            ], 201);

        } catch (\Exception $e) {
            // En cas d'erreur, supprimer l'image si elle a été uploadée
            if (isset($imagePath) && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du produit',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Mettre à jour un produit existant (ADMIN ONLY) - Support base64 et FormData
     * 
     * @param Request $request - Nouvelles données du produit
     * @param int $id - ID du produit à modifier
     * @return JsonResponse - Produit mis à jour
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Récupérer le produit à modifier
            $product = Product::find($id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Validation des données de mise à jour
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'base_price' => 'sometimes|numeric|min:0',
                'category_id' => 'sometimes|exists:categories,id',
                'image_main' => 'nullable', // Peut être base64
                'image_main_file' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:2048', // Pour compatibilité FormData
                'sort_order' => 'sometimes|integer|min:0',
                'is_active' => 'sometimes|boolean',
                'images' => 'nullable|array', // Tableau d'images base64
                'images.*.data' => 'nullable|string', // Données base64
                'images.*.alt_text' => 'nullable|string|max:255',
                'images.*.title' => 'nullable|string|max:255',
                'images.*.sort_order' => 'nullable|integer|min:0'
            ], [
                'name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'base_price.numeric' => 'Le prix de base doit être un nombre',
                'base_price.min' => 'Le prix de base ne peut pas être négatif',
                'category_id.exists' => 'La catégorie sélectionnée n\'existe pas',
                'image_main_file.image' => 'Le fichier doit être une image',
                'image_main_file.mimes' => 'Formats d\'image acceptés : jpeg, png, jpg, gif, webp',
                'image_main_file.max' => 'L\'image ne peut pas dépasser 2MB',
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

            // Vérifier la catégorie si elle est modifiée
            if ($request->has('category_id')) {
                $category = Category::find($request->category_id);
                if (!$category || !$category->is_active) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La catégorie sélectionnée n\'est pas disponible'
                    ], 422);
                }
            }

            // Traitement de la nouvelle image principale
            $oldImagePath = $product->image_main;
            $imagePath = $oldImagePath;

            // Vérifier si c'est une image base64
            if ($request->has('image_main') && $request->image_main && is_string($request->image_main)) {
                $imagePath = $this->saveBase64Image($request->image_main, 'products');
                
                // Supprimer l'ancienne image si elle existe
                if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }
            // Vérifier si c'est un fichier uploadé (compatibilité FormData)
            elseif ($request->hasFile('image_main_file')) {
                $image = $request->file('image_main_file');
                $fileName = 'product_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('products', $fileName, 'public');
                
                // Supprimer l'ancienne image si elle existe
                if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }

            // Mettre à jour les champs fournis
            if ($request->has('name')) {
                $product->name = $request->name;
                // Régénérer le slug si le nom change
                $slug = Str::slug($request->name);
                $originalSlug = $slug;
                $counter = 1;

                while (Product::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                    $slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
                $product->slug = $slug;
            }

            if ($request->has('description')) {
                $product->description = $request->description;
            }

            if ($request->has('base_price')) {
                $product->base_price = $request->base_price;
            }

            if ($request->has('category_id')) {
                $product->category_id = $request->category_id;
            }

            if ($request->has('sort_order')) {
                $product->sort_order = $request->sort_order;
            }

            if ($request->has('is_active')) {
                $product->is_active = $request->is_active;
            }

            // Mettre à jour l'image
            $product->image_main = $imagePath;

            // Sauvegarder les modifications
            $product->save();

            // Traitement des nouvelles images en base64
            if ($request->has('images') && is_array($request->images)) {
                foreach ($request->images as $imageData) {
                    if (isset($imageData['data']) && $imageData['data']) {
                        $this->saveProductImageFromBase64($product, $imageData);
                    }
                }
            }

            // Charger les relations pour la réponse
            $product->load(['category', 'variants', 'images']);

            // Formater la réponse
            $formattedProduct = [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'base_price' => $product->base_price,
                'image_main' => $product->image_main ? asset('storage/' . $product->image_main) : null,
                'category' => [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug
                ],
                'has_variants' => $product->hasVariants(),
                'variants_count' => $product->variants->count(),
                'sort_order' => $product->sort_order,
                'is_active' => $product->is_active,
                'updated_at' => $product->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Produit mis à jour avec succès',
                'data' => $formattedProduct
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du produit',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Supprimer un produit (ADMIN ONLY)
     * 
     * @param int $id - ID du produit à supprimer
     * @return JsonResponse - Message de confirmation
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Récupérer le produit à supprimer
            $product = Product::with(['variants', 'images'])->find($id);

            // Vérifier si le produit existe
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produit non trouvé'
                ], 404);
            }

            // Vérifier s'il y a des commandes associées à ce produit
            $hasOrders = $product->variants()->whereHas('orderItems')->exists();
            if ($hasOrders) {
        return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer ce produit',
                    'error' => 'Ce produit a des commandes associées. Supprimez d\'abord toutes les commandes.'
                ], 422);
            }

            // Supprimer l'image principale si elle existe
            if ($product->image_main && Storage::disk('public')->exists($product->image_main)) {
                Storage::disk('public')->delete($product->image_main);
            }

            // Supprimer toutes les images associées
            foreach ($product->images as $image) {
                if (Storage::disk('public')->exists($image->media_path)) {
                    Storage::disk('public')->delete($image->media_path);
                }
            }

            // Supprimer le produit (les variantes et images seront supprimées automatiquement via les contraintes)
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => 'Produit supprimé avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression du produit',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Sauvegarder une image base64 sur le disque
     * 
     * @param string $base64Data - Données base64 de l'image
     * @param string $folder - Dossier de destination
     * @return string|null - Chemin de l'image sauvegardée ou null si erreur
     */
    private function saveBase64Image(string $base64Data, string $folder): ?string
    {
        try {
            // Vérifier le format base64
            if (!preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
                return null;
            }

            $imageType = $matches[1];
            $base64String = substr($base64Data, strpos($base64Data, ',') + 1);
            $imageData = base64_decode($base64String);

            if ($imageData === false) {
                return null;
            }

            // Vérifier que c'est bien une image
            if (!getimagesizefromstring($imageData)) {
                return null;
            }

            // Générer un nom de fichier unique
            $fileName = 'product_' . time() . '_' . Str::random(10) . '.' . $imageType;
            $filePath = $folder . '/' . $fileName;

            // Sauvegarder sur le disque
            if (Storage::disk('public')->put($filePath, $imageData)) {
                return $filePath;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sauvegarder une image de produit à partir de base64
     * 
     * @param Product $product - Produit associé
     * @param array $imageData - Données de l'image (data, alt_text, title, sort_order)
     * @return bool - Succès de l'opération
     */
    private function saveProductImageFromBase64(Product $product, array $imageData): bool
    {
        try {
            if (!isset($imageData['data']) || !$imageData['data']) {
                return false;
            }

            // Sauvegarder l'image
            $imagePath = $this->saveBase64Image($imageData['data'], 'products/images');
            
            if (!$imagePath) {
                return false;
            }

            // Créer l'enregistrement en base de données
            $product->images()->create([
                'media_path' => $imagePath,
                'media_type' => 'image',
                'alt_text' => $imageData['alt_text'] ?? null,
                'title' => $imageData['title'] ?? null,
                'sort_order' => $imageData['sort_order'] ?? 0
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
