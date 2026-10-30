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

function branding_header_logo_url(string $name = 'header'): ?string {
    foreach (['svg', 'png', 'webp'] as $extension) {
        $url = branding_public_file('logo/' . $name . '.' . $extension);
        if ($url !== null) {
            return $url;
        }
    }

    return null;
}

/**
 * Header logo markup, or '' when no custom logo exists. An optional
 * header-dark.* is a variant for dark themes: both images are rendered and
 * dashboard.css shows the one that fits the active theme.
 */
function branding_header_logo_html(string $alt): string {
    $logoUrl = branding_header_logo_url();
    if ($logoUrl === null) {
        return '';
    }

    $darkUrl = branding_header_logo_url('header-dark');
    if ($darkUrl === null) {
        return '<img class="brand-logo" src="' . h($logoUrl) . '" alt="' . h($alt) . '">';
    }

    return '<img class="brand-logo brand-logo-light" src="' . h($logoUrl) . '" alt="' . h($alt) . '">'
        . '<img class="brand-logo brand-logo-dark" src="' . h($darkUrl) . '" alt="' . h($alt) . '">';
}
