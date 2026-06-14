<?php
declare(strict_types=1);

function branding_public_file(string $relativePath): ?string {
    $relativePath = ltrim($relativePath, '/');

    if ($relativePath === '' || str_contains($relativePath, '..') || preg_match('/[^a-zA-Z0-9._\/-]/', $relativePath)) {
        return null;
    }

    $path = '/app/branding/' . $relativePath;
    if (is_file($path)) {
        return '/branding/' . $relativePath . '?v=' . rawurlencode((string)@filemtime($path));
    }

    return null;
}

function branding_header_logo_url(): ?string {
    foreach (['logo/header.svg', 'logo/header.png', 'logo/header.webp'] as $candidate) {
        $url = branding_public_file($candidate);
        if ($url !== null) {
            return $url;
        }
    }

    return null;
}
