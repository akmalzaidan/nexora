<?php

namespace App\Services;

use App\Models\Asset;

class AssetQrService
{
    private const QR_PREFIX = 'NEXORA';

    private const QR_TYPE = 'ASSET';

    private const QR_PATTERN = '/^NEXORA:ASSET:[A-Za-z0-9\-_]+$/';

    public function payload(Asset $asset): string
    {
        return implode(':', [self::QR_PREFIX, self::QR_TYPE, $asset->asset_code]);
    }

    public function parseIdentifier(string $payload): ?string
    {
        if (! preg_match(self::QR_PATTERN, $payload)) {
            return null;
        }

        $parts = explode(':', $payload);

        return $parts[2] ?? null;
    }

    public function resolve(string $identifier): ?Asset
    {
        $code = $this->parseIdentifier($identifier) ?? $identifier;

        return Asset::withoutTrashed()
            ->where('asset_code', $code)
            ->first();
    }

    public function resolveOrFail(string $payload): Asset
    {
        $code = $this->parseIdentifier($payload) ?? $payload;

        $asset = Asset::withoutTrashed()
            ->where('asset_code', $code)
            ->first();

        if ($asset === null) {
            abort(404, 'Asset not found for QR identifier');
        }

        return $asset;
    }
}
