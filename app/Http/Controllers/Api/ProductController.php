<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Services\CloudinaryService;
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
            // Cache désactivé temporairement - exécution directe
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

            // Filtre par statut (actif/inactif)
            if ($request->has('status') && $request->status) {
                if ($request->status === 'active') {
                    $query->where('is_active', true);
                } elseif ($request->status === 'inactive') {
                    $query->where('is_active', false);
                }
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
                    'image_main' => $product->image_main,
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
            $result = [
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

            return response()->json($result, 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des produits',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Liste des produits pour l'admin (tous les produits, actifs et inactifs)
     * 
     * @param Request $request - Requête avec filtres optionnels
     * @return JsonResponse - Liste des produits filtrés et paginés
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            // Construire la requête de base - SANS filtre is_active pour l'admin
            $query = Product::with(['category' => function ($categoryQuery) {
                    $categoryQuery->with('parent'); // Charger aussi la catégorie parente
                }, 'variants' => function ($variantQuery) {
                    $variantQuery->where('is_active', true);
                }]);

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

            // Filtre par statut (actif/inactif) - pour l'admin, on peut filtrer
            if ($request->has('status') && $request->status) {
                if ($request->status === 'active') {
                    $query->where('is_active', true);
                } elseif ($request->status === 'inactive') {
                    $query->where('is_active', false);
                }
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
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            
            if ($sortBy === 'price') {
                $query->orderBy('base_price', $sortOrder);
            } elseif ($sortBy === 'name') {
                $query->orderBy('name', $sortOrder);
            } elseif ($sortBy === 'created_at') {
                $query->orderBy('created_at', $sortOrder);
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Pagination - plus de produits par page pour l'admin
            $perPage = $request->get('per_page', 50);
            $products = $query->paginate($perPage);

            // Formater les données des produits pour l'admin
            $formattedProducts = $products->getCollection()->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'base_price' => $product->base_price,
                    'image_main' => $product->image_main,
                    'is_active' => $product->is_active,
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

            // Retourner la réponse avec pagination Laravel standard
            return response()->json([
                'success' => true,
                'message' => 'Produits récupérés avec succès',
                'data' => $formattedProducts,
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem()
            ], 200);

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
            
            // Cache désactivé temporairement - exécution directe
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
                'image_main' => $product->image_main,
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
                        'is_active' => $variant->is_active,
                        'is_available' => $variant->isAvailable(),
                        'sort_order' => $variant->sort_order
                    ];
                }),
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'media_path' => $image->media_path,
                        'media_type' => $image->media_type,
                        'alt_text' => $image->alt_text,
                        'title' => $image->title,
                        'sort_order' => $image->sort_order
                    ];
                }),
                'videos' => $product->videos->map(function ($video) {
                    return [
                        'id' => $video->id,
                        'media_path' => $video->media_path,
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

            $result = [
                'success' => true,
                'message' => 'Produit récupéré avec succès',
                'data' => $formattedProduct
            ];

            return response()->json($result, 200);

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

            // Traitement de l'image principale avec Cloudinary
            $imageUrl = null;
            $cloudinaryService = new CloudinaryService();
            
            // Vérifier si c'est une image base64
            if ($request->has('image_main') && $request->image_main && is_string($request->image_main)) {
                $uploadResult = $cloudinaryService->uploadBase64Image($request->image_main, 'bs_shop/products');
                if ($uploadResult['success']) {
                    $imageUrl = $uploadResult['secure_url'];
                }
            }
            // Vérifier si c'est un fichier uploadé (compatibilité FormData)
            elseif ($request->hasFile('image_main_file')) {
                $image = $request->file('image_main_file');
                $uploadResult = $cloudinaryService->uploadImage($image, 'bs_shop/products');
                if ($uploadResult['success']) {
                    $imageUrl = $uploadResult['secure_url'];
                }
            }

            // Créer le produit
            $product = Product::create([
                'name' => $request->name,
                'slug' => $slug,
                'description' => $request->description,
                'base_price' => $request->base_price,
                'category_id' => $request->category_id,
                'image_main' => $imageUrl,
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
                'image_main' => $product->image_main,
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
                'image_main' => $product->image_main,
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

            // Uploader l'image vers Cloudinary
            $cloudinaryService = new CloudinaryService();
            $uploadResult = $cloudinaryService->uploadBase64Image($imageData['data'], 'bs_shop/products/images');
            
            if (!$uploadResult['success']) {
                return false;
            }

            // Créer l'enregistrement en base de données
            $product->images()->create([
                'media_path' => $uploadResult['secure_url'],
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

    /**
     * Créer plusieurs produits en masse (ADMIN ONLY)
     * 
     * @param Request $request - Données des produits avec catégorie commune
     * @return JsonResponse - Produits créés
     */
    public function storeBatch(Request $request): JsonResponse
    {
        try {
            // Augmenter les limites pour les gros volumes
            ini_set('memory_limit', '1024M'); // Augmenté à 1GB
            ini_set('max_execution_time', 600); // Augmenté à 10 minutes
            ini_set('max_input_time', 300); // Limite d'entrée
            ini_set('post_max_size', '100M'); // Taille max des données POST
            ini_set('upload_max_filesize', '50M'); // Taille max des fichiers
            // Validation des données de création en masse
            $validator = Validator::make($request->all(), [
                'category_id' => 'required|exists:categories,id',
                'products' => 'required|array|min:1|max:100', // Limite de 100 produits par batch
                'products.*.name' => 'required|string|max:255',
                'products.*.description' => 'required|string',
                'products.*.base_price' => 'required|numeric|min:0',
                'products.*.image_main' => 'required|string', // Image base64 obligatoire
                'products.*.is_active' => 'nullable|boolean',
                'products.*.sort_order' => 'nullable|integer|min:0',
                // Validation des variantes (optionnelles)
                'products.*.variants' => 'nullable|array',
                'products.*.variants.*.name' => 'required_with:products.*.variants|string|max:255',
                'products.*.variants.*.price' => 'required_with:products.*.variants|numeric|min:0',
                'products.*.variants.*.sku' => 'nullable|string|max:100',
                'products.*.variants.*.stock_quantity' => 'nullable|integer|min:0',
                'products.*.variants.*.is_active' => 'nullable|boolean',
                'products.*.variants.*.sort_order' => 'nullable|integer|min:0',
                // Limite de variantes par produit
                'products.*.variants' => 'max:5' // Maximum 5 variantes par produit
            ], [
                'category_id.required' => 'La catégorie est obligatoire',
                'category_id.exists' => 'La catégorie sélectionnée n\'existe pas',
                'products.required' => 'Au moins un produit est requis',
                'products.array' => 'Les produits doivent être un tableau',
                'products.min' => 'Au moins un produit est requis',
                'products.max' => 'Maximum 50 produits par création en masse',
                'products.*.name.required' => 'Le nom du produit est obligatoire',
                'products.*.name.max' => 'Le nom ne peut pas dépasser 255 caractères',
                'products.*.description.required' => 'La description du produit est obligatoire',
                'products.*.base_price.required' => 'Le prix de base est obligatoire',
                'products.*.base_price.numeric' => 'Le prix de base doit être un nombre',
                'products.*.base_price.min' => 'Le prix de base ne peut pas être négatif',
                'products.*.image_main.required' => 'L\'image principale est obligatoire',
                'products.*.is_active.boolean' => 'Le statut actif doit être vrai ou faux',
                'products.*.sort_order.integer' => 'L\'ordre doit être un nombre entier',
                'products.*.sort_order.min' => 'L\'ordre ne peut pas être négatif',
                'products.*.variants.max' => 'Maximum 5 variantes par produit'
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

            // Démarrer une transaction pour garantir l'intégrité
            \DB::beginTransaction();

            try {
                $createdProducts = [];
                $cloudinaryService = new CloudinaryService();
                $sortOrder = 0;
                
                // Optimisation pour gros volumes : traiter par petits lots
                $batchSize = 3; // Réduit à 3 pour éviter la surcharge avec variantes
                $products = array_chunk($request->products, $batchSize);

                foreach ($products as $productBatch) {
                    // Commencer une sous-transaction pour chaque lot
                    \DB::beginTransaction();
                    
                    try {
                        foreach ($productBatch as $productData) {
                    // Générer le slug unique à partir du nom
                    $slug = Str::slug($productData['name']);
                    $originalSlug = $slug;
                    $counter = 1;

                    // Vérifier l'unicité du slug
                    while (Product::where('slug', $slug)->exists()) {
                        $slug = $originalSlug . '-' . $counter;
                        $counter++;
                    }

                    // Uploader l'image vers Cloudinary avec optimisation
                    $imageUrl = null;
                    if (isset($productData['image_main']) && $productData['image_main']) {
                        // Optimiser l'image avant upload
                        $optimizedImage = $this->optimizeBase64Image($productData['image_main']);
                        
                        $uploadResult = $cloudinaryService->uploadBase64Image($optimizedImage, 'bs_shop/products');
                        if ($uploadResult['success']) {
                            $imageUrl = $uploadResult['secure_url'];
                        } else {
                            throw new \Exception('Erreur lors de l\'upload de l\'image pour le produit: ' . $productData['name']);
                        }
                        
                        // Libérer la mémoire
                        unset($optimizedImage);
                        unset($productData['image_main']);
                    }

                    // Créer le produit
                    $product = Product::create([
                        'name' => $productData['name'],
                        'slug' => $slug,
                        'description' => $productData['description'],
                        'base_price' => $productData['base_price'],
                        'category_id' => $request->category_id,
                        'image_main' => $imageUrl,
                        'sort_order' => $productData['sort_order'] ?? $sortOrder,
                        'is_active' => $productData['is_active'] ?? true
                    ]);

                    // Créer les variantes si elles existent - Optimisé pour gros volumes
                    $createdVariants = [];
                    \Log::info('Données du produit reçues:', ['product' => $productData]);
                    if (isset($productData['variants']) && is_array($productData['variants']) && count($productData['variants']) > 0) {
                        \Log::info('Variantes trouvées pour le produit:', ['variants' => $productData['variants']]);
                        
                        // Préparer les données des variantes pour insertion en masse
                        $variantsToInsert = [];
                        foreach ($productData['variants'] as $index => $variantData) {
                            $variantsToInsert[] = [
                                'product_id' => $product->id,
                                'name' => $variantData['name'],
                                'price' => $variantData['price'],
                                'sku' => $variantData['sku'] ?? null,
                                'stock_quantity' => $variantData['stock_quantity'] ?? 0,
                                'is_active' => $variantData['is_active'] ?? true,
                                'sort_order' => $variantData['sort_order'] ?? $index,
                                'created_at' => now(),
                                'updated_at' => now()
                            ];
                        }
                        
                        // Insertion en masse des variantes
                        \DB::table('product_variants')->insert($variantsToInsert);
                        
                        // Récupérer les variantes créées pour la réponse
                        $createdVariants = \DB::table('product_variants')
                            ->where('product_id', $product->id)
                            ->select('id', 'name', 'price', 'sku', 'stock_quantity', 'is_active', 'sort_order')
                            ->get()
                            ->toArray();
                            
                        // Libérer la mémoire des données de variantes
                        unset($variantsToInsert);
                    }

                    $productResponse = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'slug' => $product->slug,
                        'description' => $product->description,
                        'base_price' => $product->base_price,
                        'image_main' => $product->image_main,
                        'category_id' => $product->category_id,
                        'sort_order' => $product->sort_order,
                        'is_active' => $product->is_active,
                        'created_at' => $product->created_at,
                        'variants' => $createdVariants
                    ];
                    
                    \Log::info('Produit créé avec variantes:', ['product' => $productResponse]);
                    $createdProducts[] = $productResponse;

                        $sortOrder++;
                    }
                    
                    // Valider la sous-transaction
                    \DB::commit();
                    
                    // Libérer la mémoire après chaque lot
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                    
                    } catch (\Exception $e) {
                        // Annuler la sous-transaction en cas d'erreur
                        \DB::rollback();
                        throw $e;
                    }
                }
                
                // Valider la transaction principale
                \DB::commit();

                // Invalider le cache des produits
                Cache::forget('products_index_' . md5(serialize([])));

                return response()->json([
                    'success' => true,
                    'message' => count($createdProducts) . ' produit(s) créé(s) avec succès',
                    'data' => [
                        'products' => $createdProducts,
                        'count' => count($createdProducts),
                        'category' => [
                            'id' => $category->id,
                            'name' => $category->name,
                            'slug' => $category->slug
                        ]
                    ]
                ], 201);
            } catch (\Exception $e) {
                // Annuler la transaction en cas d'erreur
                \DB::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création en masse des produits',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Optimise une image base64 pour réduire sa taille
     */
    private function optimizeBase64Image($base64Image, $maxWidth = 800, $quality = 80)
    {
        try {
            // Décoder l'image base64
            $imageData = base64_decode(preg_replace('#^data:image/[^;]+;base64,#', '', $base64Image));
            
            // Créer une ressource d'image
            $image = imagecreatefromstring($imageData);
            if (!$image) {
                return $base64Image; // Retourner l'original si l'optimisation échoue
            }
            
            // Obtenir les dimensions originales
            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);
            
            // Calculer les nouvelles dimensions
            if ($originalWidth <= $maxWidth) {
                imagedestroy($image);
                return $base64Image; // Pas besoin d'optimiser
            }
            
            $newHeight = ($originalHeight * $maxWidth) / $originalWidth;
            
            // Créer une nouvelle image redimensionnée
            $optimizedImage = imagecreatetruecolor($maxWidth, $newHeight);
            
            // Préserver la transparence pour PNG
            imagealphablending($optimizedImage, false);
            imagesavealpha($optimizedImage, true);
            
            // Redimensionner l'image
            imagecopyresampled(
                $optimizedImage, $image,
                0, 0, 0, 0,
                $maxWidth, $newHeight,
                $originalWidth, $originalHeight
            );
            
            // Capturer l'image optimisée
            ob_start();
            imagejpeg($optimizedImage, null, $quality);
            $optimizedData = ob_get_contents();
            ob_end_clean();
            
            // Nettoyer la mémoire
            imagedestroy($image);
            imagedestroy($optimizedImage);
            
            // Retourner l'image optimisée en base64
            return 'data:image/jpeg;base64,' . base64_encode($optimizedData);
            
        } catch (\Exception $e) {
            \Log::warning('Erreur lors de l\'optimisation de l\'image: ' . $e->getMessage());
            return $base64Image; // Retourner l'original en cas d'erreur
        }
    }

}
