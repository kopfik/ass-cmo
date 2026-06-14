<?php
declare(strict_types=1);

function row_value(array $row, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return (string)$row[$key];
        }
    }

    return '';
}

function normalized_notes_lines(string $notes): array {
    $lines = [];

    foreach (preg_split('/\r?\n/', $notes) ?: [] as $line) {
        $line = trim($line);
        $line = trim($line, "[] \t");

        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

function notes_metadata(string $notes): array {
    $metadata = [];

    foreach (normalized_notes_lines($notes) as $line) {
        if (preg_match('/^(ssh[_-]user)\s*:\s*([^\s,;]+)$/i', $line, $m)) {
            $metadata['ssh_user'] = trim($m[2]);
        }
    }

    return $metadata;
}


function is_safe_notes_web_url(string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);

    if ($scheme === null) {
        return false;
    }

    // Notes app links intentionally allow only normal web URLs.
    // Do not allow javascript:, data:, file:, custom URI schemes or other non-web schemes here.
    return in_array(strtolower($scheme), ['http', 'https'], true);
}

function is_safe_launcher_host(string $host): bool {
    if ($host === '') {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    // RFC 1123 hostname: labels of letters/digits/hyphens, separated by dots.
    return preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $host) === 1;
}

function is_safe_ssh_user(string $user): bool {
    if ($user === '') {
        return false;
    }

    // Standard Unix username: starts with letter or underscore, letters/digits/underscores/hyphens/dots, max 64 chars.
    return preg_match('/^[a-zA-Z_][a-zA-Z0-9_\-\.]{0,63}$/', $user) === 1;
}

function notes_app_links(string $notes, bool $asswebProtocol = false): array {
    $links = [];

    foreach (normalized_notes_lines($notes) as $line) {
        if (preg_match('/^ssh[_-]user\s*:/i', $line)) {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9 _.-]{1,24})\s*:\s*(https?:\/\/\S+)$/i', $line, $m)) {
            $label = trim($m[1]);
            $url = rtrim(trim($m[2]), ',');

            if (!is_safe_notes_web_url($url)) {
                continue;
            }

            $href = $asswebProtocol ? ('assweb://' . rawurlencode($url)) : $url;
            $class = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $label) ?: 'web');

            if (strtolower($label) === 'shell') {
                $label = 'SHELL';
                $class = 'shell';
            }

            $links[] = [
                'label' => $label,
                'url' => $href,
                'class' => $class,
                'new_tab' => !$asswebProtocol,
            ];
        }
    }

    return $links;
}

function row_command_actions(array $row): array {
    $actions = [];

    foreach ($row as $key => $value) {
        if (!is_string($key) || !str_ends_with($key, '_oneliner')) {
            continue;
        }

        $command = trim((string)$value);

        if ($command === '' || str_starts_with($command, 'No outdated ')) {
            continue;
        }

        $label = $key === 'linux_bulk_update_oneliner' ? 'COPY BULK UPDATE' : 'COPY COMMAND';

        $actions[] = [
            'label' => $label,
            'command' => $command,
            'class' => 'agent-update',
        ];
    }

    return $actions;
}

function row_actions(array $row, array $ctx): array {
    $commandActions = row_command_actions($row);

    $ip = row_value($row, ['ip', 'primary_ipv4_addr', 'primary_ip']);
    if ($ip === '') {
        return $commandActions;
    }

    $notes = row_value($row, ['notes']);
    $metadata = notes_metadata($notes);

    $os = strtolower(row_value($row, ['os_name', 'os', 'os_family']));
    $sshUser = trim($metadata['ssh_user'] ?? (string)$ctx['dashboard_ssh_user']);

    $isWindows = str_contains($os, 'windows') || str_contains($os, 'microsoft');

    $actions = $commandActions;

    if ($isWindows) {
        if (is_safe_launcher_host($ip)) {
            $actions[] = [
                'label' => 'RDP',
                'url' => 'assrdp://' . rawurlencode($ip),
                'class' => 'rdp',
                'new_tab' => false,
            ];
        }
    } else {
        if (is_safe_launcher_host($ip) && ($sshUser === '' || is_safe_ssh_user($sshUser))) {
            $sshTarget = $sshUser !== '' ? ($sshUser . '@' . $ip) : $ip;

            $actions[] = [
                'label' => 'SSH',
                'url' => 'assssh://' . rawurlencode($sshTarget),
                'class' => 'ssh',
                'new_tab' => false,
            ];
        }
    }

    $agentState = strtolower(row_value($row, ['agent_state']));
    $agentPlatform = strtolower(row_value($row, ['agent_platform']));

    if ($agentState === 'outdated') {
        $baseUrl = (string)($ctx['base_url'] ?? '');

        if ($agentPlatform === 'windows' || ($agentPlatform === '' && $isWindows)) {
            $actions[] = [
                'label' => 'AGENT UPDATE ONELINER',
                'command' => windows_agent_manual_update_command($baseUrl),
                'class' => 'agent-update',
            ];
        } else {
            $actions[] = [
                'label' => 'AGENT UPDATE ONELINER',
                'command' => linux_agent_manual_update_command($baseUrl),
                'class' => 'agent-update',
            ];
        }
    }

    return array_merge($actions, notes_app_links($notes, (bool)($ctx['assweb_protocol'] ?? false)));
}

function render_action_buttons(array $row, array $ctx): string {
    $actions = row_actions($row, $ctx);

    if ($actions === []) {
        return '<span class="muted">-</span>';
    }

    $html = '';

    foreach ($actions as $action) {
        if (isset($action['command'])) {
            $html .= '<button type="button" class="action action-' . h($action['class']) . '" data-copy-command="' . h($action['command']) . '">' . h($action['label']) . '</button>';
            continue;
        }

        $target = $action['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '';
        $html .= '<a class="action action-' . h($action['class']) . '" href="' . h($action['url']) . '"' . $target . '>' . h($action['label']) . '</a>';
    }

    return $html;
}
