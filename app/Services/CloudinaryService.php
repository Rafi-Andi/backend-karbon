<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudinaryService
{
    public const TIMEOUT_SECONDS = 30;

    public const MOCK_CLOUD_NAME = 'mock';

    /** Prefix penanda nilai tersimpan yang menunjuk ke Cloudinary public_id. */
    public const PUBLIC_ID_PREFIX = 'cloudinary:';

    protected ?string $cloudName;

    protected ?string $apiKey;

    protected ?string $apiSecret;

    protected string $folder;

    protected bool $mock;

    protected int $timeout;

    public function __construct(
        ?string $cloudName = null,
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?string $folder = null,
        ?bool $mock = null,
        ?int $timeout = null,
    ) {
        $this->cloudName = $cloudName ?? config('services.cloudinary.cloud_name');
        $this->apiKey = $apiKey ?? config('services.cloudinary.api_key');
        $this->apiSecret = $apiSecret ?? config('services.cloudinary.api_secret');
        $this->folder = $folder ?? (string) config('services.cloudinary.folder', 'karbonkita');
        $this->mock = $mock ?? (bool) config('services.cloudinary.mock', false);
        $this->timeout = $timeout ?? (int) config('services.cloudinary.timeout', self::TIMEOUT_SECONDS);
    }

    public static function fromConfig(): self
    {
        return new self;
    }

    public function isMock(): bool
    {
        return $this->mock
            || ! $this->cloudName
            || ! $this->apiKey
            || ! $this->apiSecret;
    }

    /**
     * Upload foto ke Cloudinary dan kembalikan public_id (termasuk folder).
     * Nilai ini yang disimpan di DB dengan prefix {@see self::PUBLIC_ID_PREFIX};
     * URL lengkap dibangun ulang saat dibaca via {@see publicUrl()}.
     */
    public function upload(UploadedFile $file, string $folder): string
    {
        $folder = trim($folder, '/');

        if ($this->isMock()) {
            return $folder.'/mock-'.uniqid().'.'.$this->extension($file);
        }

        $timestamp = time();
        $params = [
            'folder' => $folder,
            'timestamp' => $timestamp,
        ];

        $signature = $this->signature($params);

        try {
            $response = Http::timeout($this->timeout)
                ->attach('file', (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName())
                ->post($this->uploadUrl(), array_merge($params, [
                    'api_key' => $this->apiKey,
                    'signature' => $signature,
                ]));
        } catch (\Throwable $e) {
            throw new RuntimeException('Cloudinary upload failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Cloudinary upload request failed (HTTP {$response->status()}).");
        }

        $publicId = (string) data_get($response->json(), 'public_id', '');

        if ($publicId === '') {
            throw new RuntimeException('Cloudinary upload returned no public_id.');
        }

        return $publicId;
    }

    /**
     * Bangun URL publik (delivery) dari public_id Cloudinary.
     */
    public function publicUrl(string $publicId): string
    {
        $cloud = $this->cloudName ?: self::MOCK_CLOUD_NAME;

        return "https://res.cloudinary.com/{$cloud}/image/upload/".ltrim($publicId, '/');
    }

    protected function uploadUrl(): string
    {
        return "https://api.cloudinary.com/v1_1/{$this->cloudName}/image/upload";
    }

    /**
     * Signature Cloudinary: sha1 dari parameter terurut (kecuali file/api_key)
     * yang digabung `key=value&` lalu ditambah api_secret.
     *
     * @param  array<string, int|string>  $params
     */
    protected function signature(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return sha1(implode('&', $pairs).$this->apiSecret);
    }

    protected function extension(UploadedFile $file): string
    {
        $ext = $file->getClientOriginalExtension();

        return $ext !== '' ? strtolower($ext) : 'jpg';
    }
}
