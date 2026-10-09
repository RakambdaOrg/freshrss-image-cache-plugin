<?php

namespace ImageCache;

use Minz_Extension;

class Settings
{
    const DEFAULT_CACHE_URL = "https://example.com/pic";
    const DEFAULT_CACHE_DISABLED_URL = "";
    const DEFAULT_RECACHE_URL = "";
    const DEFAULT_CACHE_ACCESS_TOKEN = "";
    const DEFAULT_VIDEO_VOLUME = 1;
    const DEFAULT_UPLOAD_RETRY_COUNT = 0;
    const DEFAULT_UPLOAD_RETRY_DELAY = 0;
    const DEFAULT_MAX_CACHE_ELEMENTS = 5000;
    const DEFAULT_REMOVE_WRONG_TAG = false;

    private Minz_Extension $settingsProvider;

    public function __construct(Minz_Extension $settingsProvider)
    {
        $this->settingsProvider = $settingsProvider;
    }

    public function getImageCacheUrl(): string
    {
        return $this->settingsProvider->getUserConfigurationString('image_cache_url') ?? self::DEFAULT_CACHE_URL;
    }

    public function getInternalImageCacheUrl(): string
    {
        return $this->settingsProvider->getUserConfigurationString('internal_image_cache_url') ?? $this->getImageCacheUrl();
    }

    public function getImageCacheAccessToken(): string
    {
        return $this->settingsProvider->getUserConfigurationString('image_cache_access_token') ?? self::DEFAULT_CACHE_ACCESS_TOKEN;
    }

    /**
     * @return array<string>
     */
    public function getImageDisabledUrl(): array
    {
        return $this->splitEntries($this->settingsProvider->getUserConfigurationString('image_cache_disabled_url') ?? self::DEFAULT_CACHE_URL);
    }

    /**
     * @return array<string>
     */
    public function getImageRecacheUrl(): array
    {
        return $this->splitEntries($this->settingsProvider->getUserConfigurationString('image_recache_url') ?? self::DEFAULT_RECACHE_URL);
    }

    public function getVideoDefaultVolume(): float
    {
        return $this->settingsProvider->getUserConfigurationInt('video_default_volume') ?? self::DEFAULT_VIDEO_VOLUME;
    }

    public function getUploadRetryCount(): int
    {
        return $this->settingsProvider->getUserConfigurationInt('upload_retry_count') ?? self::DEFAULT_UPLOAD_RETRY_COUNT;
    }

    public function getUploadRetryDelay(): int
    {
        return $this->settingsProvider->getUserConfigurationInt('upload_retry_delay') ?? self::DEFAULT_UPLOAD_RETRY_DELAY;
    }

    public function getMaxCacheElements(): int
    {
        return $this->settingsProvider->getUserConfigurationInt('max_cache_elements') ?? self::DEFAULT_MAX_CACHE_ELEMENTS;
    }

    public function isRemoveWrongTag(): bool
    {
        return $this->settingsProvider->getUserConfigurationBool('remove_wrong_tag') ?? self::DEFAULT_REMOVE_WRONG_TAG;
    }

    /**
     * @return array<string>
     */
    private function splitEntries(string $entries): array
    {
        return array_filter(array_map(
                fn($value): string => trim($value),
                preg_split("/[,\n]/", $entries)
        ));
    }
}