<?php
declare(strict_types=1);

function asscmo_normalize_web_base_url(string $baseUrl): string {
    $baseUrl = rtrim(trim($baseUrl), '/');

    if ($baseUrl === '') {
        throw new InvalidArgumentException('ASS-CMO base URL is empty.');
    }

    if (preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
        throw new InvalidArgumentException('ASS-CMO base URL must not contain whitespace or control characters.');
    }

    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    $host = parse_url($baseUrl, PHP_URL_HOST);
    $query = parse_url($baseUrl, PHP_URL_QUERY);
    $fragment = parse_url($baseUrl, PHP_URL_FRAGMENT);

    if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
        throw new InvalidArgumentException('ASS-CMO base URL must use http:// or https://.');
    }

    if (!is_string($host) || $host === '') {
        throw new InvalidArgumentException('ASS-CMO base URL must include a host.');
    }

    if ($query !== null || $fragment !== null) {
        throw new InvalidArgumentException('ASS-CMO base URL must not include query strings or fragments.');
    }

    return $baseUrl;
}

function asscmo_sh_quote(string $value): string {
    return escapeshellarg($value);
}

function asscmo_ps_quote(string $value): string {
    return "'" . str_replace("'", "''", $value) . "'";
}

function linux_agent_install_command(string $baseUrl): string {
    $baseUrl = asscmo_normalize_web_base_url($baseUrl);
    $installerUrl = $baseUrl . '/agents/linux/install-ass-cmo-agent.sh';

    return 'tmp="$(mktemp)" && trap \'rm -f "$tmp"\' EXIT && curl -fsSL ' . asscmo_sh_quote($installerUrl) . ' -o "$tmp" && sh "$tmp" --base-url ' . asscmo_sh_quote($baseUrl);
}

function windows_agent_install_command(string $baseUrl): string {
    $baseUrl = asscmo_normalize_web_base_url($baseUrl);
    $installerUrl = $baseUrl . '/agents/windows/install-ass-cmo-agent.ps1';

    return '[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; $p=Join-Path $env:TEMP ' . asscmo_ps_quote('install-ass-cmo-agent.ps1') . '; Invoke-WebRequest -UseBasicParsing ' . asscmo_ps_quote($installerUrl) . ' -OutFile $p; powershell.exe -NoProfile -ExecutionPolicy Bypass -File $p -BaseUrl ' . asscmo_ps_quote($baseUrl);
}

function linux_agent_manual_update_command(string $baseUrl): string {
    return linux_agent_install_command($baseUrl);
}

function windows_agent_manual_update_command(string $baseUrl): string {
    return windows_agent_install_command($baseUrl);
}

function linux_handlers_install_command(string $baseUrl): string {
    $baseUrl = asscmo_normalize_web_base_url($baseUrl);
    $installerUrl = $baseUrl . '/agents/handlers/linux/install-ass-cmo-uri-handlers.sh';

    return 'curl -fsSL ' . asscmo_sh_quote($installerUrl) . ' | ASSCMO_HANDLER_BASE_URL=' . asscmo_sh_quote($baseUrl) . ' sh';
}

function windows_handlers_install_command(string $baseUrl): string {
    $baseUrl = asscmo_normalize_web_base_url($baseUrl);
    $installerUrl = $baseUrl . '/agents/handlers/windows/install-ass-cmo-uri-handlers.ps1';

    return '$p=Join-Path $env:TEMP ' . asscmo_ps_quote('install-ass-cmo-uri-handlers.ps1') . '; [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -UseBasicParsing ' . asscmo_ps_quote($installerUrl) . ' -OutFile $p; powershell.exe -NoProfile -ExecutionPolicy Bypass -File $p -InstallUrl ' . asscmo_ps_quote($installerUrl);
}

function build_copy_command_groups(array $ctx): array {
    $baseUrl = (string)$ctx['base_url'];

    return [
        [
            'label' => 'Agent installers',
            'items' => [
                ['label' => 'Linux install/update', 'command' => linux_agent_install_command($baseUrl)],
                ['label' => 'Windows install/update', 'command' => windows_agent_install_command($baseUrl)],
            ],
        ],
        [
            'label' => 'Handlers',
            'items' => [
                ['label' => 'Linux', 'command' => linux_handlers_install_command($baseUrl)],
                ['label' => 'Windows', 'command' => windows_handlers_install_command($baseUrl)],
            ],
        ],
    ];
}

function build_quick_links(array $ctx, string $active = ''): array {
    $adminerUrl = (string)$ctx['adminer_url'];
    $adminerLinkUrl = $adminerUrl;
    $adminerNewTab = true;
    $adminerWebUrl = '';

    if (($ctx['assweb_protocol'] ?? false) && preg_match('~^https?://~i', $adminerUrl)) {
        $adminerLinkUrl = 'assweb://' . rawurlencode($adminerUrl);
        $adminerNewTab = false;
        // Preserve raw HTTP(S) target for direct open in non-PWA browser mode.
        $adminerWebUrl = $adminerUrl;
    }

    $links = [
        ['label' => 'About', 'url' => '#about', 'new_tab' => false, 'active' => false, 'modal' => 'about'],
    ];

    if ($adminerUrl !== '') {
        $links[] = ['label' => 'Adminer', 'url' => $adminerLinkUrl, 'new_tab' => $adminerNewTab, 'active' => false, 'web_url' => $adminerWebUrl];
    }

    return $links;
}
