<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;

class BannerController extends Controller
{
    /**
     * Configure les limites PHP pour les uploads volumineux
     */
    private function configureUploadLimits()
    {
        ini_set('upload_max_filesize', '100M');
        ini_set('post_max_size', '100M');
        ini_set('max_execution_time', 300);
        ini_set('max_input_time', 300);
        ini_set('memory_limit', '256M');
    }

    /**
     * Liste des bannières actives (côté public)
     * 
     * @return JsonResponse - Liste des bannières actives triées par position
     */
    public function index(): JsonResponse
    {
        try {
            $banners = Banner::active()
                ->ordered()
                ->get()
                ->map(function ($banner) {
                    return [
                        'id' => $banner->id,
                        'title' => $banner->title,
                        'image_url' => $banner->image_url,
                        'link_url' => $banner->link_url,
                        'position' => $banner->position,
                        'created_at' => $banner->created_at,
                        'updated_at' => $banner->updated_at
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Bannières récupérées avec succès',
                'data' => $banners
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des bannières',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Liste des bannières pour l'admin (toutes les bannières)
     * 
     * @return JsonResponse - Liste complète des bannières
     */
    public function adminIndex(): JsonResponse
    {
        try {
            $banners = Banner::ordered()
                ->get()
                ->map(function ($banner) {
                    return [
                        'id' => $banner->id,
                        'title' => $banner->title,
                        'image_url' => $banner->image_url,
                        'link_url' => $banner->link_url,
                        'is_active' => $banner->is_active,
                        'position' => $banner->position,
                        'created_at' => $banner->created_at,
                        'updated_at' => $banner->updated_at
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Bannières récupérées avec succès',
                'data' => $banners
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des bannières',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Afficher une bannière spécifique
     * 
     * @param int $id - ID de la bannière
     * @return JsonResponse - Détails de la bannière
     */
    public function show(int $id): JsonResponse
    {
        try {
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bannière non trouvée'
                ], 404);
            }

            $formattedBanner = [
                'id' => $banner->id,
                'title' => $banner->title,
                'image_url' => $banner->image_url,
                'link_url' => $banner->link_url,
                'is_active' => $banner->is_active,
                'position' => $banner->position,
                'created_at' => $banner->created_at,
                'updated_at' => $banner->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Bannière récupérée avec succès',
                'data' => $formattedBanner
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la bannière',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Créer une nouvelle bannière (ADMIN ONLY)
     * 
     * @param Request $request - Données de la bannière avec image
     * @return JsonResponse - Bannière créée
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Configurer les limites d'upload
            $this->configureUploadLimits();
            
            // Validation des données
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'image' => 'nullable', // Peut être base64 ou fichier
                'image_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:102400', // Pour compatibilité FormData (100MB)
                'link_url' => 'nullable|url',
                'is_active' => 'nullable|boolean',
                'position' => 'nullable|integer|min:0'
            ], [
                'title.required' => 'Le titre de la bannière est obligatoire',
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères',
                'image_file.image' => 'Le fichier doit être une image',
                'image_file.mimes' => 'Formats d\'image acceptés : jpeg, png, jpg, gif, webp',
                'image_file.max' => 'L\'image ne peut pas dépasser 100MB',
                'link_url.url' => 'L\'URL doit être valide',
                'position.integer' => 'La position doit être un nombre entier',
                'position.min' => 'La position ne peut pas être négative'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Traitement de l'image
            $imagePath = null;
            
            // Vérifier si c'est une image base64
            if ($request->has('image') && $request->image && is_string($request->image)) {
                $imagePath = $this->saveBase64Image($request->image, 'banners');
            }
            // Vérifier si c'est un fichier uploadé (compatibilité FormData)
            elseif ($request->hasFile('image_file')) {
                $image = $request->file('image_file');
                $fileName = 'banner_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('banners', $fileName, 'public');
            }

            // Créer la bannière
            $banner = Banner::create([
                'title' => $request->title,
                'image' => $imagePath,
                'link_url' => $request->link_url,
                'is_active' => $request->is_active ?? true,
                'position' => $request->position ?? 0
            ]);

            // Formater la réponse
            $formattedBanner = [
                'id' => $banner->id,
                'title' => $banner->title,
                'image_url' => $banner->image_url,
                'link_url' => $banner->link_url,
                'is_active' => $banner->is_active,
                'position' => $banner->position,
                'created_at' => $banner->created_at,
                'updated_at' => $banner->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Bannière créée avec succès',
                'data' => $formattedBanner
            ], 201);

        } catch (\Exception $e) {
            // En cas d'erreur, supprimer l'image si elle a été uploadée
            if (isset($imagePath) && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la bannière',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Mettre à jour une bannière existante (ADMIN ONLY)
     * 
     * @param Request $request - Nouvelles données de la bannière
     * @param int $id - ID de la bannière à modifier
     * @return JsonResponse - Bannière mise à jour
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Configurer les limites d'upload
            $this->configureUploadLimits();
            
            // Récupérer la bannière à modifier
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bannière non trouvée'
                ], 404);
            }

            // Validation des données
            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|string|max:255',
                'image' => 'nullable', // Peut être base64
                'image_file' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:102400', // Pour compatibilité FormData (100MB)
                'link_url' => 'sometimes|nullable|url',
                'is_active' => 'sometimes|boolean',
                'position' => 'sometimes|integer|min:0'
            ], [
                'title.max' => 'Le titre ne peut pas dépasser 255 caractères',
                'image_file.image' => 'Le fichier doit être une image',
                'image_file.mimes' => 'Formats d\'image acceptés : jpeg, png, jpg, gif, webp',
                'image_file.max' => 'L\'image ne peut pas dépasser 100MB',
                'link_url.url' => 'L\'URL doit être valide',
                'position.integer' => 'La position doit être un nombre entier',
                'position.min' => 'La position ne peut pas être négative'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de validation',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Traitement de la nouvelle image
            $oldImagePath = $banner->image;
            $imagePath = $oldImagePath;

            // Vérifier si c'est une image base64
            if ($request->has('image') && $request->image && is_string($request->image)) {
                $imagePath = $this->saveBase64Image($request->image, 'banners');
                
                // Supprimer l'ancienne image si elle existe
                if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }
            // Vérifier si c'est un fichier uploadé (compatibilité FormData)
            elseif ($request->hasFile('image_file')) {
                $image = $request->file('image_file');
                $fileName = 'banner_' . time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('banners', $fileName, 'public');
                
                // Supprimer l'ancienne image si elle existe
                if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }
            }

            // Mettre à jour les champs fournis
            if ($request->has('title')) {
                $banner->title = $request->title;
            }

            if ($request->has('link_url')) {
                $banner->link_url = $request->link_url;
            }

            if ($request->has('is_active')) {
                $banner->is_active = $request->is_active;
            }

            if ($request->has('position')) {
                $banner->position = $request->position;
            }

            // Mettre à jour l'image
            $banner->image = $imagePath;

            // Sauvegarder les modifications
            $banner->save();

            // Formater la réponse
            $formattedBanner = [
                'id' => $banner->id,
                'title' => $banner->title,
                'image_url' => $banner->image_url,
                'link_url' => $banner->link_url,
                'is_active' => $banner->is_active,
                'position' => $banner->position,
                'updated_at' => $banner->updated_at
            ];

            return response()->json([
                'success' => true,
                'message' => 'Bannière mise à jour avec succès',
                'data' => $formattedBanner
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour de la bannière',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Supprimer une bannière (ADMIN ONLY)
     * 
     * @param int $id - ID de la bannière à supprimer
     * @return JsonResponse - Message de confirmation
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Récupérer la bannière à supprimer
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bannière non trouvée'
                ], 404);
            }

            // Supprimer l'image si elle existe
            if ($banner->image && Storage::disk('public')->exists($banner->image)) {
                Storage::disk('public')->delete($banner->image);
            }

            // Supprimer la bannière
            $banner->delete();

            return response()->json([
                'success' => true,
                'message' => 'Bannière supprimée avec succès'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la bannière',
                'error' => 'Une erreur est survenue'
            ], 500);
        }
    }

    /**
     * Basculer le statut actif/inactif d'une bannière (ADMIN ONLY)
     * 
     * @param int $id - ID de la bannière
     * @return JsonResponse - Bannière mise à jour
     */
    public function toggleStatus(int $id): JsonResponse
    {
        try {
            $banner = Banner::find($id);

            if (!$banner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bannière non trouvée'
                ], 404);
            }

            $banner->is_active = !$banner->is_active;
            $banner->save();

            return response()->json([
                'success' => true,
                'message' => 'Statut de la bannière mis à jour avec succès',
                'data' => [
                    'id' => $banner->id,
                    'title' => $banner->title,
                    'is_active' => $banner->is_active
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du statut',
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
            $fileName = 'banner_' . time() . '_' . Str::random(10) . '.' . $imageType;
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
}
