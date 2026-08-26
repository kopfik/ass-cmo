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

    // Optional "-- group-by: column" header turns the rendered table into
    // collapsible groups keyed by that output column. Views without it render
    // exactly as before.
    $groupBy = '';

    if (preg_match('/^\s*--\s*group-by:\s*(.+)$/mi', $sql, $m)) {
        $candidate = trim($m[1]);
        if (preg_match('/^[a-zA-Z0-9_]+$/', $candidate)) {
            $groupBy = $candidate;
        }
    }

    // Optional "-- hide-columns: a, b" header keeps columns out of the rendered
    // table while still selecting them, the way notes and tags already work:
    // useful for values that only feed the row action buttons.
    $hideColumns = [];

    if (preg_match('/^\s*--\s*hide-columns:\s*(.+)$/mi', $sql, $m)) {
        foreach (preg_split('/[\s,]+/', trim($m[1])) ?: [] as $candidate) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $candidate)) {
                $hideColumns[] = $candidate;
            }
        }
    }

    // Optional "-- group-actions: true" header moves the row action buttons onto
    // the group header row. Only correct when every row in a group shares the
    // same target, so it stays opt-in: grouping by location, for example, puts
    // several different hosts in one group and must keep per-row actions.
    $groupActions = $groupBy !== ''
        && preg_match('/^\s*--\s*group-actions:\s*(?:true|yes|1)\s*$/mi', $sql) === 1;

    $id = pathinfo($path, PATHINFO_FILENAME);
    $id = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $id) ?: $id;

    return [
        'id' => $id,
        'label' => $label,
        'description' => $description,
        'group_by' => $groupBy,
        'group_actions' => $groupActions,
        'hide_columns' => $hideColumns,
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
