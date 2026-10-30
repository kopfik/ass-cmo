<?php
declare(strict_types=1);

require_once '/app/includes/common.php';
require_once '/app/includes/commands.php';
require_once '/app/includes/actions.php';
require_once '/app/includes/table.php';
require_once '/app/includes/layout.php';

// ass-roads: company-wide connection crossroads.
// Read-only by design: one fixed query over the roads_hosts view, no forms, no writes.
// Which hosts and columns are listed is decided by the roads_hosts view in the
// database (database/scripts/003_roads_readonly_user.sql), not by this file.

$ctx = app_context();
// Employees connect with their own SSH user, never the admin dashboard default.
$ctx['dashboard_ssh_user'] = '';

$rows  = [];
$error = '';

$host = envv('POSTGRES_HOST', 'postgres');
$port = envv('POSTGRES_PORT', '5432');
$db   = envv('POSTGRES_DB', 'inventory_db');
$user = envv('ROADS_DB_USER', '');
$pass = envv('ROADS_DB_PASSWORD', '');

if ($user === '' || $pass === '') {
    error_log('ass-roads: ROADS_DB_USER or ROADS_DB_PASSWORD is not configured.');
    $error = 'Database credentials are not configured. Check server logs.';
} else {
    try {
        $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET statement_timeout = '5000ms'");
        $stmt = $pdo->query('SELECT * FROM roads_hosts ORDER BY hostname');
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (Throwable $e) {
        error_log('ass-roads: query failed: ' . $e->getMessage());
        $error = 'Failed to load hosts. Check server logs.';
    }
}

// os_name only selects RDP or SSH in the action buttons.
$columns = visible_table_columns($rows, ['notes', 'tags', 'os_name']);

$handlerCommands = [];
try {
    $handlerCommands = [
        ['label' => 'Linux', 'command' => linux_handlers_install_command((string)$ctx['base_url'])],
        ['label' => 'Windows', 'command' => windows_handlers_install_command((string)$ctx['base_url'])],
    ];
} catch (InvalidArgumentException $e) {
    error_log('ass-roads: handler commands unavailable: ' . $e->getMessage());
}

$logoUrl = branding_header_logo_url();

// ── Render ───────────────────────────────────────────────────────────────────

render_head('ASS-ROADS', $ctx);
?>
<div class="app app-v2 roads">
    <aside class="brand">
        <?php if ($logoUrl !== null): ?>
            <img class="brand-logo" src="<?= h($logoUrl) ?>" alt="ASS-ROADS">
        <?php else: ?>
            <div class="title">ASS-ROADS</div>
        <?php endif; ?>
        <div class="subtitle">Connection crossroads</div>
        <div class="instance"><?= h($ctx['instance']) ?></div>
    </aside>

    <?php render_top('Hosts', 'Shared list of hosts and their connection links.', function () use ($rows): void { ?>
        <div class="meta-box meta-box-compact">
            <div class="meta-row"><span>Rows</span><strong><?= h(count($rows)) ?></strong></div>
            <div class="meta-row"><span>Rendered</span><strong><?= h(date('Y-m-d H:i:s')) ?></strong></div>
        </div>
    <?php }); ?>

    <nav class="sidebar">
        <div class="sidebar-tools">
            <?php if ($handlerCommands !== []): ?>
                <div class="sidebar-tools-title">Handlers</div>
                <div class="copy-command-panel">
                    <?php foreach ($handlerCommands as $item): ?>
                        <button type="button" class="copy-command" data-copy-command="<?= h($item['command']) ?>">
                            <span class="copy-command-label"><?= h($item['label']) ?></span>
                            <span class="copy-command-button">Copy</span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="muted">SSH and RDP buttons need the ASS-CMO URI handlers installed on your computer. Copy the command for your system and run it once.</p>
            <?php endif; ?>
        </div>
    </nav>

    <main class="content">
        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php elseif ($rows === []): ?>
            <div class="empty">No hosts listed.</div>
        <?php else: ?>
            <?php render_table_tools(count($rows)); ?>
            <?php // One header per column of the multi-column layout; hidden on narrow windows. ?>
            <div class="roads-head" aria-hidden="true">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="roads-head-group">
                        <span>Actions</span>
                        <?php foreach ($columns as $col): ?>
                            <span><?= h(display_column_label((string)$col)) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <?php render_dashboard_table($rows, $columns, $ctx); ?>
        <?php endif; ?>
    </main>
</div>

<div id="command-launcher" class="command-launcher" hidden>
    <div class="command-launcher-backdrop" data-launcher-close="1"></div>
    <div class="command-launcher-panel" role="dialog" aria-modal="true" aria-label="Command launcher">
        <div class="command-launcher-input-wrap">
            <img class="command-launcher-prefix" src="/branding/logo/icon-192.png" alt="" aria-hidden="true" width="28" height="28">
            <input id="command-launcher-input" class="command-launcher-input" type="search" placeholder="Type hostname, IP, SSH, RDP, WEB action..." autocomplete="off">
        </div>
        <div id="command-launcher-results" class="command-launcher-results"></div>
        <div class="command-launcher-help">Enter runs selected action · ↑/↓ selects · Esc closes</div>
    </div>
</div>

<?php render_theme_script(); ?>

<script src="/assets/dashboard.js?v=<?= h(asset_version('dashboard.js')) ?>" defer></script>
<?php render_page_end(); ?>
