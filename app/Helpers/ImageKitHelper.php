<?php

namespace App\Helpers;

use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;

class ImageKitHelper
{
    /**
     * Upload a single file to ImageKit using form-data
     */
    public static function uploadFile(UploadedFile $file, string $fileName = null): ?string
    {
        $apiUrl = 'https://upload.imagekit.io/api/v1/files/upload';
        $privateKey = config('services.imagekit.private');
        $folder = config('services.imagekit.folder') ?? '/blogs';
        $fileName = $fileName ?? $file->getClientOriginalName();

        try {
            $client = new Client();

            $response = $client->post($apiUrl, [
                'auth' => [$privateKey, ''],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($file->getRealPath(), 'r'),
                    ],
                    [
                        'name' => 'fileName',
                        'contents' => $fileName,
                    ],
                    [
                        'name' => 'folder',
                        'contents' => $folder,
                    ],
                    [
                        'name' => 'useUniqueFileName',
                        'contents' => 'true',
                    ],
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            return $body['url'] ?? null;
        } catch (\Throwable $e) {
            // Log::error('ImageKit Upload Failed: ' . $e->getMessage());
            return null;
        }
    }


    public static function uploadBase64Image(?string $base64, string $fileName = null): ?string
    {
        if (!$base64) return null;
    
        $apiUrl = 'https://upload.imagekit.io/api/v1/files/upload';
    
        $privateKey = config('services.imagekit.private');
        $folder = config('services.imagekit.folder') ?? '/uploads';
        $fileName = $fileName ?? 'file_' . time();
    
        try {
            $client = new Client();
    
            $response = $client->post($apiUrl, [
                'auth' => [$privateKey, ''],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $base64, // ImageKit accepts base64 directly
                    ],
                    ['name' => 'fileName', 'contents' => $fileName],
                    ['name' => 'folder', 'contents' => $folder],
                    ['name' => 'useUniqueFileName', 'contents' => 'true'],
                ],
            ]);
    
            $body = json_decode($response->getBody(), true);
    
            return $body['url'] ?? null;
    
        } catch (\Throwable $e) {
            // \Log::error('ImageKit upload failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload multiple files from form-data
     *
     * @param UploadedFile[] $files
     * @return array URLs of uploaded images
     */
    public static function uploadMultipleFiles(array $files): array
    {
        $urls = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $urls[] = self::uploadFile($file);
            }
        }

        return array_filter($urls); // only successful uploads
    }
}
