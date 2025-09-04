<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class CloudinaryService
{
    private $cloudinary;

    public function __construct()
    {
        // Configuration Cloudinary depuis les variables d'environnement
        $cloudinaryUrl = env('CLOUDINARY_URL');
        
        if (!$cloudinaryUrl) {
            throw new \Exception('CLOUDINARY_URL not configured');
        }

        // Parser l'URL Cloudinary
        $parsedUrl = parse_url($cloudinaryUrl);
        $apiKey = $parsedUrl['user'];
        $apiSecret = $parsedUrl['pass'];
        $cloudName = $parsedUrl['host'];

        // Configuration Cloudinary
        Configuration::instance([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url' => [
                'secure' => true
            ]
        ]);

        $this->cloudinary = new Cloudinary();
    }

    /**
     * Uploader une image vers Cloudinary
     *
     * @param UploadedFile $file
     * @param string $folder
     * @param array $options
     * @return array
     */
    public function uploadImage(UploadedFile $file, string $folder = 'bs_shop', array $options = []): array
    {
        try {
            $uploadOptions = array_merge([
                'folder' => $folder,
                'resource_type' => 'image',
                'quality' => 'auto',
                'fetch_format' => 'auto',
                'transformation' => [
                    'width' => 1200,
                    'height' => 1200,
                    'crop' => 'limit'
                ]
            ], $options);

            $result = $this->cloudinary->uploadApi()->upload(
                $file->getRealPath(),
                $uploadOptions
            );

            return [
                'success' => true,
                'public_id' => $result['public_id'],
                'secure_url' => $result['secure_url'],
                'url' => $result['url'],
                'width' => $result['width'],
                'height' => $result['height'],
                'format' => $result['format'],
                'bytes' => $result['bytes']
            ];

        } catch (\Exception $e) {
            Log::error('Cloudinary upload error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Uploader une image base64 vers Cloudinary
     *
     * @param string $base64Data
     * @param string $folder
     * @param array $options
     * @return array
     */
    public function uploadBase64Image(string $base64Data, string $folder = 'bs_shop', array $options = []): array
    {
        try {
            $uploadOptions = array_merge([
                'folder' => $folder,
                'resource_type' => 'image',
                'quality' => 'auto',
                'fetch_format' => 'auto',
                'transformation' => [
                    'width' => 1200,
                    'height' => 1200,
                    'crop' => 'limit'
                ]
            ], $options);

            $result = $this->cloudinary->uploadApi()->upload(
                $base64Data,
                $uploadOptions
            );

            return [
                'success' => true,
                'public_id' => $result['public_id'],
                'secure_url' => $result['secure_url'],
                'url' => $result['url'],
                'width' => $result['width'],
                'height' => $result['height'],
                'format' => $result['format'],
                'bytes' => $result['bytes']
            ];

        } catch (\Exception $e) {
            Log::error('Cloudinary base64 upload error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Supprimer une image de Cloudinary
     *
     * @param string $publicId
     * @return array
     */
    public function deleteImage(string $publicId): array
    {
        try {
            $result = $this->cloudinary->uploadApi()->destroy($publicId);

            return [
                'success' => $result['result'] === 'ok',
                'result' => $result['result']
            ];

        } catch (\Exception $e) {
            Log::error('Cloudinary delete error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Générer une URL d'image avec transformations
     *
     * @param string $publicId
     * @param array $transformations
     * @return string
     */
    public function getImageUrl(string $publicId, array $transformations = []): string
    {
        return $this->cloudinary->image($publicId)->toUrl($transformations);
    }

    /**
     * Générer une URL d'image optimisée pour le web
     *
     * @param string $publicId
     * @param int $width
     * @param int $height
     * @return string
     */
    public function getOptimizedImageUrl(string $publicId, int $width = 800, int $height = 600): string
    {
        $transformations = [
            'width' => $width,
            'height' => $height,
            'crop' => 'fill',
            'quality' => 'auto',
            'fetch_format' => 'auto'
        ];

        return $this->getImageUrl($publicId, $transformations);
    }
}
