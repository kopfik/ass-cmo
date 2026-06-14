<?php
declare(strict_types=1);

function render_head(string $title, array $ctx): void {
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="application-name" content="ASS-CMO">
    <meta name="apple-mobile-web-app-title" content="ASS-CMO">
    <meta name="theme-color" content="#111827">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/branding/logo/icon-192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/branding/logo/favicon-32.png">
    <link rel="apple-touch-icon" href="/branding/logo/icon-192.png">
    <link rel="stylesheet" href="/assets/dashboard.css?v=<?= h(app_version_query($ctx)) ?>">
</head>
<body>
    <?php
}

function render_brand(array $ctx): void {
    $logoUrl = branding_header_logo_url();
    ?>
    <aside class="brand">
        <?php if ($logoUrl !== null): ?>
            <img class="brand-logo" src="<?= h($logoUrl) ?>" alt="ASS-CMO">
        <?php else: ?>
            <div class="title">ASS-CMO</div>
        <?php endif; ?>
        <div class="subtitle">Admins Secure Server Connection Manager &amp; Overview</div>
        <div class="instance"><?= h($ctx['instance']) ?></div>
    </aside>
    <?php
}

function render_theme_switcher(): void {
    ?>
            <div class="theme-switcher" aria-label="Theme selector">
                <span>Theme:</span>
                <button type="button" class="theme-button" data-theme-choice="auto">Auto</button>
                <button type="button" class="theme-button" data-theme-choice="light">Light</button>
                <button type="button" class="theme-button" data-theme-choice="dark">Dark</button>
                <button type="button" class="theme-button" data-theme-choice="midnight">Midnight</button>
                <button type="button" class="theme-button" data-theme-choice="retro">Retro</button>
                <button type="button" class="theme-button" data-theme-choice="dust">Dust</button>
                <button type="button" class="theme-button" data-theme-choice="dust-dark">Dust Dark</button>
                <button type="button" class="theme-button" data-theme-choice="rose-pine-moon">Rosé Pine Moon</button>
                <button type="button" class="theme-button" data-theme-choice="rose-pine">Rosé Pine</button>
            </div>
    <?php
}

function render_top(string $title, string $description = '', ?callable $metaRenderer = null): void {
    ?>
    <header class="top">
        <div>
            <h1><?= h($title) ?></h1>
            <p><?= h($description) ?></p>
        </div>
        <div class="meta">
            <?php render_theme_switcher(); ?>
            <?php if ($metaRenderer !== null): ?>
                <?php $metaRenderer(); ?>
            <?php endif; ?>
        </div>
    </header>
    <?php
}

function render_sidebar_versions(array $ctx): void {
    ?>
        <div class="sidebar-tools-title sidebar-tools-title-spaced">Versions</div>
        <div class="sidebar-panel sidebar-version-panel meta-box">
          <div class="meta-row"><span>ASS-CMO</span><strong><?= h($ctx['app_version']) ?></strong></div>
          <div class="meta-row"><span>Linux agent</span><strong><?= h($ctx['linux_agent_version']) ?></strong></div>
          <div class="meta-row"><span>Windows agent</span><strong><?= h($ctx['windows_agent_version']) ?></strong></div>
        </div>
    <?php
}

function render_sidebar(array $ctx, array $views, ?string $selectedViewId, array $copyCommandGroups, string $activeTool = ''): void {
    $quickLinks = build_quick_links($ctx, $activeTool);
    ?>
    <nav class="sidebar">
        <div class="nav-title">Views</div>
        <?php foreach ($views as $id => $view): ?>
            <a class="nav-item <?= $id === $selectedViewId ? 'active' : '' ?>" href="/?view=<?= h(rawurlencode((string)$id)) ?>">
                <?= h($view['label']) ?>
            </a>
        <?php endforeach; ?>

        <div class="sidebar-tools">
            <div class="sidebar-tools-title">Tools</div>
            <?php foreach ($quickLinks as $link): ?>
                <?php $target = ($link['new_tab'] ?? true) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
                <?php if (($link['modal'] ?? '') === 'about'): ?>
                    <a class="sidebar-tool-link" href="#about" data-about-open="1">
                        <?= h($link['label']) ?>
                    </a>
                <?php else: ?>
                    <a class="sidebar-tool-link <?= ($link['active'] ?? false) ? 'active' : '' ?>" href="<?= h($link['url']) ?>"<?= $target ?>>
                        <?= h($link['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php foreach ($copyCommandGroups as $group): ?>
                    <div class="sidebar-tools-title sidebar-tools-title-spaced"><?= h($group['label']) ?></div>
                    <div class="copy-command-panel">
                        <?php foreach ($group['items'] as $item): ?>
                            <button type="button" class="copy-command" data-copy-command="<?= h($item['command']) ?>">
                                <span class="copy-command-label"><?= h($item['label']) ?></span>
                                <span class="copy-command-button">Copy</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

            <?php render_sidebar_versions($ctx); ?>
        </div>
    </nav>
    <?php
}

function render_theme_script(): void {
    ?>
<script src="/assets/theme.js" defer></script>
    <?php
}

function render_page_end(): void {
    ?>
</body>
</html>
    <?php
}
