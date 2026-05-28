<?php

namespace App\Support;

class MediaUrl
{
    public static function storedPath(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '' || str_starts_with($raw, 'data:') || str_starts_with($raw, 'blob:')) {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            $path = parse_url($raw, PHP_URL_PATH) ?: '';
            if (!preg_match('#/(storage|api/media)/#', $path)) {
                return null;
            }
        } else {
            $path = $raw;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'api/media/')) {
            $path = substr($path, strlen('api/media/'));
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        $path = ltrim($path, '/');
        $path = implode('/', array_map('rawurldecode', explode('/', $path)));

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    public static function toPublicUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'data:') || str_starts_with($raw, 'blob:')) {
            return $raw;
        }

        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            $path = parse_url($raw, PHP_URL_PATH) ?: '';
            if (!preg_match('#/(storage|api/media)/#', $path)) {
                return $raw;
            }
        }

        $storedPath = self::storedPath($raw);
        if (!$storedPath) {
            return null;
        }

        return url('/api/media/' . implode('/', array_map('rawurlencode', explode('/', $storedPath))));
    }

    public static function imagePayload(?string $path): ?array
    {
        $storedPath = self::storedPath($path);
        if (!$storedPath) {
            return null;
        }

        return [
            'url' => self::toPublicUrl($storedPath),
            'path' => $storedPath,
        ];
    }
}
