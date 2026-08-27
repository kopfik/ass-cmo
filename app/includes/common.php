<?php
declare(strict_types=1);

require_once __DIR__ . '/branding.php';

function envv(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function env_bool(string $key, bool $default = false): bool {
    $value = strtolower(trim(envv($key, $default ? 'true' : 'false')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function asscmo_enrollment_pepper(): string {
    $pepper = trim(envv('ASSCMO_ENROLLMENT_PEPPER', ''));

    if ($pepper === '' || $pepper === 'change-this-enrollment-pepper' || str_starts_with($pepper, 'REPLACE_ME')) {
        throw new RuntimeException('ASS-CMO enrollment pepper is not configured.');
    }

    return $pepper;
}

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function read_version_file(string $path, string $default = 'unknown'): string {
    if (!is_readable($path)) {
        return $default;
    }

    $value = trim((string)file_get_contents($path));
    return $value === '' ? $default : $value;
}

function app_adminer_url(string $instance): string {
    $adminerUrl = trim(envv('ASSCMO_ADMINER_URL', ''));

    if ($adminerUrl !== '') {
        $parts = parse_url($adminerUrl);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));

        if (($scheme === 'http' || $scheme === 'https') && !empty($parts['host'])) {
            return $adminerUrl;
        }

        return '';
    }

    $adminerBaseHost = preg_replace('/^[^.]+\./', '', $instance) ?: $instance;
    return 'https://adminer.' . $adminerBaseHost . '/';
}

function app_context(): array {
    $instance = envv('ASSCMO_INSTANCE_NAME', 'ASS-CMO');
    $baseUrl = rtrim(envv('ASSCMO_BASE_URL', 'https://' . $instance), '/');

    return [
        'instance' => $instance,
        'base_url' => $baseUrl,
        'adminer_url' => app_adminer_url($instance),
        'views_dir' => envv('ASSCMO_DASHBOARD_VIEWS_DIR', '/app/dashboard-views'),
        'dashboard_ssh_user' => trim(envv('ASSCMO_DASHBOARD_SSH_USER', '')),
        'assweb_protocol' => env_bool('ASSCMO_ASSWEB_PROTOCOL', false),
        'app_version' => read_version_file('/app/meta/VERSION'),
        'linux_agent_version' => read_version_file('/app/agents/linux/VERSION'),
        'windows_agent_version' => read_version_file('/app/agents/windows/VERSION'),
    ];
}

function app_version_query(array $ctx): string {
    return rawurlencode((string)($ctx['app_version'] ?? 'unknown'));
}

/**
 * Cache-busting token for a file in the public assets directory.
 *
 * Uses the file's modification time so an edited asset is picked up on the next
 * request. The application version is only a fallback: it does not change when
 * an asset is edited within a release, which leaves browsers on a stale copy.
 */
function asset_version(string $file): string {
    $file = basename($file);

    $roots = [
        '/var/www/html/assets',
        __DIR__ . '/../public/assets',
    ];

    foreach ($roots as $root) {
        $mtime = @filemtime($root . '/' . $file);

        if ($mtime !== false) {
            return rawurlencode((string)$mtime);
        }
    }

    return rawurlencode(read_version_file('/app/meta/VERSION'));
}
