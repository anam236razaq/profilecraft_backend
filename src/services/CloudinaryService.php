<?php

class CloudinaryService
{
    private string $cloudName;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->cloudName = $_ENV['CLOUDINARY_CLOUD_NAME'] ?? '';
        $this->apiKey = $_ENV['CLOUDINARY_API_KEY'] ?? '';
        $this->apiSecret = $_ENV['CLOUDINARY_API_SECRET'] ?? '';
    }

    /**
     * Upload a file to Cloudinary (signed)
     */
    public function upload(string $filePath, string $folder): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $timestamp = time();

        // Build string to sign - only folder and timestamp (alphabetical order)
        $stringToSign = "folder=" . $folder . "&timestamp=" . $timestamp;
        $signature = hash("sha256", $stringToSign . $this->apiSecret);

        $url = "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile($filePath),
            'api_key' => $this->apiKey,
            'timestamp' => $timestamp,
            'folder' => $folder,
            'signature' => $signature
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return null;
        }

        if ($httpCode !== 200) {
            return null;
        }

        $result = json_decode($response, true);
        return $result['secure_url'] ?? null;
    }

    /**
     * Upload profile avatar
     */
    public function uploadProfileImage(string $filePath): ?string
    {
        return $this->upload($filePath, "profilecraft/uploads/images");
    }

    /**
     * Upload template thumbnail
     */
    public function uploadThumbnail(string $filePath): ?string
    {
        return $this->upload($filePath, "profilecraft/uploads/thumbnails");
    }
}