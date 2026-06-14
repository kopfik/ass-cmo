<?php
declare(strict_types=1);

function strip_sql_comments_for_check(string $sql): string {
    $sql = preg_replace('/--.*$/m', '', $sql);
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);

    // Remove single-quoted string literals before keyword checks.
    // This prevents harmless values like '🔵 UPDATE' from tripping the write-operation blacklist.
    $sql = preg_replace("/'(?:''|[^'])*'/", "''", $sql);

    return $sql ?? '';
}

function starts_with_sql_allowed(string $sql): bool {
    $trimmed = ltrim(strip_sql_comments_for_check($sql));
    return preg_match('/^(SELECT|WITH)\b/i', $trimmed) === 1;
}

function sql_is_reasonably_safe(string $sql): bool {
    $check = strip_sql_comments_for_check($sql);

    if (!starts_with_sql_allowed($check)) {
        return false;
    }

    if (substr_count($check, ';') > 1) {
        return false;
    }

    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|COPY|CALL|DO)\b/i', $check)) {
        return false;
    }

    return true;
}

function parse_view_file(string $path): ?array {
    $sql = file_get_contents($path);
    if ($sql === false) {
        return null;
    }

    $label = pathinfo($path, PATHINFO_FILENAME);
    $description = '';

    if (preg_match('/^\s*--\s*label:\s*(.+)$/mi', $sql, $m)) {
        $label = trim($m[1]);
    }

    if (preg_match('/^\s*--\s*description:\s*(.+)$/mi', $sql, $m)) {
        $description = trim($m[1]);
    }

    $id = pathinfo($path, PATHINFO_FILENAME);
    $id = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $id) ?: $id;

    return [
        'id' => $id,
        'label' => $label,
        'description' => $description,
        'path' => $path,
        'sql' => $sql,
    ];
}

function load_views(string $dir): array {
    $views = [];

    foreach (glob(rtrim($dir, '/') . '/*.sql') ?: [] as $path) {
        $view = parse_view_file($path);
        if ($view !== null) {
            $views[$view['id']] = $view;
        }
    }

    uasort($views, fn($a, $b) => strcmp(basename($a['path']), basename($b['path'])));
    return $views;
}

function selected_view_id(array $views, mixed $requested): ?string {
    $fallback = array_key_first($views);

    if (!is_string($requested) || !isset($views[$requested])) {
        return $fallback;
    }

    return $requested;
}
