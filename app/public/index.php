<?php
declare(strict_types=1);

require_once '/app/includes/common.php';
require_once '/app/includes/views.php';
require_once '/app/includes/commands.php';
require_once '/app/includes/actions.php';
require_once '/app/includes/table.php';
require_once '/app/includes/layout.php';
require_once '/app/includes/about.php';
require_once '/app/includes/enrollment_auth.php';

function enrollment_admin_pdo(): PDO {
    $host = envv('POSTGRES_HOST', 'postgres');
    $port = envv('POSTGRES_PORT', '5432');
    $db   = envv('POSTGRES_DB', 'inventory_db');
    $user = envv('POSTGRES_USER', 'asscmo');
    $pass = envv('POSTGRES_PASSWORD', '');
    return new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function enrollment_sweep_expired_requests(PDO $pdo): void {
    $pendingStmt = $pdo->prepare("
        UPDATE agent_enrollment_requests
           SET status = 'expired',
               expired_reason = 'timeout'
         WHERE status = 'pending'
           AND expires_at <= CURRENT_TIMESTAMP
    ");
    $pendingStmt->execute();

    asscmo_expire_approved_enrollment_and_cleanup_orphan($pdo);
}

function dashboard_admin_actor(): string {
    $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remoteAddr !== '' ? 'dashboard-ui ' . $remoteAddr : 'dashboard-ui';
}

function dashboard_asset_version(array $ctx): string {
    $mtime = @filemtime('/app/public/assets/dashboard.js');
    if ($mtime !== false) {
        return rawurlencode((string)$mtime);
    }

    return app_version_query($ctx);
}

function render_enrollment_main(array $rows, string $error, ?array $flash, string $csrfToken, ?int $highlightId): void {
    ?>
    <?php if ($flash !== null): ?>
        <div class="enrollment-flash enrollment-flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>
    <div class="enrollment-list">
        <?php if ($rows === []): ?>
            <div class="empty">No pending enrollment requests.</div>
        <?php else: ?>
            <?php foreach ($rows as $req): ?>
                <?php $isExpired = (bool)($req['is_expired'] ?? false); ?>
                <?php $reqId = (int)$req['id']; ?>
                <div id="enrollment-req-<?= h($reqId) ?>"
                     class="enrollment-card<?= $isExpired ? ' enrollment-expired' : '' ?><?= ($highlightId !== null && $reqId === $highlightId) ? ' enrollment-highlight' : '' ?>">
                    <div class="enrollment-card-header">
                        <span class="enrollment-card-id">Request #<?= h($reqId) ?></span>
                        <?php if ($isExpired): ?>
                            <span class="enrollment-badge enrollment-badge-expired">Expired</span>
                        <?php else: ?>
                            <span class="enrollment-badge enrollment-badge-pending">Pending</span>
                        <?php endif; ?>
                    </div>

                    <div class="enrollment-pairing-row">
                        <span class="enrollment-pairing-label">Pairing code</span>
                        <code class="enrollment-pairing-code"><?= $req['pairing_code'] !== null ? h($req['pairing_code']) : '—' ?></code>
                    </div>

                    <div class="meta-box meta-box-compact enrollment-meta">
                        <div class="meta-row"><span>Hostname</span><strong><?= h($req['hostname']) ?></strong></div>
                        <?php if (($req['fqdn'] ?? null) !== null): ?>
                            <div class="meta-row"><span>FQDN</span><strong><?= h($req['fqdn']) ?></strong></div>
                        <?php endif; ?>
                        <div class="meta-row"><span>UID</span><strong><?= ($req['uid'] ?? null) !== null ? h($req['uid']) : '—' ?></strong></div>
                        <div class="meta-row"><span>OS type</span><strong><?= ($req['os_type'] ?? null) !== null ? h($req['os_type']) : '—' ?></strong></div>
                        <div class="meta-row"><span>Agent version</span><strong><?= ($req['agent_version'] ?? null) !== null ? h($req['agent_version']) : '—' ?></strong></div>
                        <div class="meta-row"><span>Request IP</span><strong><?= h($req['request_ip']) ?></strong></div>
                        <div class="meta-row"><span>Created</span><strong><?= h($req['created_at']) ?></strong></div>
                        <div class="meta-row"><span>Expires</span><strong><?= h($req['expires_at']) ?></strong></div>
                    </div>

                    <?php if (!$isExpired): ?>
                        <div class="enrollment-actions">
                            <form method="post" action="/?view=enrollment" class="enrollment-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="enrollment_action" value="approve">
                                <input type="hidden" name="request_id" value="<?= h($reqId) ?>">
                                <label class="enrollment-confirm-label">
                                    <input type="checkbox" name="confirm_pairing" value="1" required>
                                    I confirm this pairing code matches the target machine console.
                                </label>
                                <button type="submit" class="enrollment-btn enrollment-btn-approve">Approve</button>
                            </form>
                            <form method="post" action="/?view=enrollment" class="enrollment-form enrollment-form-inline">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="enrollment_action" value="deny">
                                <input type="hidden" name="request_id" value="<?= h($reqId) ?>">
                                <button type="submit" class="enrollment-btn enrollment-btn-deny">Deny</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php if ($highlightId !== null): ?>
    <script>
    (function () {
        var el = document.getElementById('enrollment-req-<?= (int)$highlightId ?>');
        if (el) { el.scrollIntoView({block: 'center'}); }
    }());
    </script>
    <?php endif; ?>
    <?php
}

function render_agent_auth_main(array $rows, string $error, ?array $flash, string $csrfToken): void {
    ?>
    <?php if ($flash !== null): ?>
        <div class="enrollment-flash enrollment-flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>
    <div class="table-tools filter">
        <label class="filter-label" for="agent-auth-filter">Filter</label>
        <input id="agent-auth-filter" class="filter-input" type="search"
               placeholder="Type to filter (&ge;3 chars), or: all &middot; date" autocomplete="off">
        <span id="agent-auth-count" class="row-count"></span>
    </div>
    <div class="enrollment-list" id="agent-auth-list">
        <?php if ($rows === []): ?>
            <div class="empty">No agent auth rows found.</div>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $uid = (string)$row['uid'];
                $hostname = (string)($row['hostname'] ?? '');
                $location = (string)($row['location'] ?? '');
                $ipv4 = (string)($row['primary_ipv4_addr'] ?? '');
                $confirmParts = array_filter([
                    $hostname !== '' ? "host {$hostname}" : '',
                    $ipv4 !== '' ? "IP {$ipv4}" : '',
                    $location !== '' ? "location {$location}" : '',
                    "UID {$uid}",
                ]);
                $confirmText = 'Revoke agent secret for ' . implode(', ', $confirmParts) . '? This host must fresh re-enroll to recover.';
                $authLabel   = $hostname !== '' ? $hostname : $uid;
                $authCreated = isset($row['created_at']) ? (string)$row['created_at'] : '';
                ?>
                <div class="enrollment-card"
                     data-agent-auth-card
                     data-auth-label="<?= h($authLabel) ?>"
                     data-auth-created="<?= h($authCreated) ?>"
                >
                    <div class="enrollment-card-header">
                        <span class="enrollment-card-id"><?= h($hostname !== '' ? $hostname : $uid) ?></span>
                        <span class="enrollment-badge <?= ((string)$row['status'] === 'revoked') ? 'enrollment-badge-expired' : 'enrollment-badge-pending' ?>"><?= h((string)$row['status']) ?></span>
                    </div>

                    <div class="meta-box meta-box-compact enrollment-meta">
                        <div class="meta-row"><span>Hostname</span><strong><?= $hostname !== '' ? h($hostname) : '—' ?></strong></div>
                        <?php if (($row['fqdn'] ?? null) !== null && (string)$row['fqdn'] !== ''): ?>
                            <div class="meta-row"><span>FQDN</span><strong><?= h((string)$row['fqdn']) ?></strong></div>
                        <?php endif; ?>
                        <div class="meta-row"><span>Location</span><strong><?= $location !== '' ? h($location) : '—' ?></strong></div>
                        <div class="meta-row"><span>Primary IPv4</span><strong><?= $ipv4 !== '' ? h($ipv4) : '—' ?></strong></div>
                        <div class="meta-row"><span>UID</span><strong><?= h($uid) ?></strong></div>
                        <div class="meta-row"><span>Agent auth status</span><strong><?= h((string)$row['status']) ?></strong></div>
                        <div class="meta-row"><span>Last auth</span><strong><?= ($row['last_auth_at'] ?? null) !== null ? h((string)$row['last_auth_at']) : '—' ?></strong></div>
                        <div class="meta-row"><span>Last inventory</span><strong><?= ($row['last_inventory_at'] ?? null) !== null ? h((string)$row['last_inventory_at']) : (($row['inventory_update_time'] ?? null) !== null ? h((string)$row['inventory_update_time']) : '—') ?></strong></div>
                        <div class="meta-row"><span>Created</span><strong><?= ($row['created_at'] ?? null) !== null ? h((string)$row['created_at']) : '—' ?></strong></div>
                        <div class="meta-row"><span>Revoked at</span><strong><?= ($row['revoked_at'] ?? null) !== null ? h((string)$row['revoked_at']) : '—' ?></strong></div>
                    </div>

                    <?php if ((string)$row['status'] !== 'revoked' && ($row['revoked_at'] ?? null) === null): ?>
                        <div class="enrollment-actions">
                            <form method="post" action="/?view=agent-auth" class="enrollment-form" onsubmit="return window.confirm(<?= h(json_encode($confirmText, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>);">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="agent_auth_action" value="revoke">
                                <input type="hidden" name="uid" value="<?= h($uid) ?>">
                                <label class="enrollment-confirm-label">
                                    <input type="checkbox" name="confirm_revoke" value="1" required>
                                    I confirm I want to revoke this per-host secret. Recovery is fresh re-enrollment.
                                </label>
                                <button type="submit" class="enrollment-btn enrollment-btn-deny">Revoke</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
}

// ── Bootstrap ────────────────────────────────────────────────────────────────

$ctx  = app_context();
$views = load_views((string)$ctx['views_dir']);

// Append synthetic enrollment view (kept last so SQL views remain the default)
$views['enrollment'] = [
    'id'          => 'enrollment',
    'label'       => 'Enrollment approvals',
    'description' => 'Review and approve or deny pending agent enrollment requests.',
    'path'        => 'synthetic:enrollment',
    'sql'         => '',
];
$views['agent-auth'] = [
    'id'          => 'agent-auth',
    'label'       => 'Revoke agents',
    'description' => 'Search existing per-host agent auth rows and revoke compromised or retired secrets.',
    'path'        => 'synthetic:agent-auth',
    'sql'         => '',
];

$selectedId  = selected_view_id($views, $_GET['view'] ?? null);
$currentView = $selectedId !== null ? $views[$selectedId] : null;
$isEnrollment = $selectedId === 'enrollment';
$isAgentAuth = $selectedId === 'agent-auth';

// ── Data loading ─────────────────────────────────────────────────────────────

$rows  = [];
$error = '';

$enrollmentRows     = [];
$agentAuthRows      = [];
$enrollmentError    = '';
$agentAuthError     = '';
$csrfToken          = '';
$flashMessage       = null;
$highlightRequestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : null;

if ($isEnrollment || $isAgentAuth) {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => $isHttps,
        ]);
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = (string)$_SESSION['csrf_token'];

    if (isset($_SESSION['enrollment_flash'])) {
        $flashMessage = $_SESSION['enrollment_flash'];
        unset($_SESSION['enrollment_flash']);
    }

    if ($isEnrollment && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedCsrf   = (string)($_POST['csrf_token'] ?? '');
        $postedAction = (string)($_POST['enrollment_action'] ?? '');
        $postedId     = (int)($_POST['request_id'] ?? 0);

        if (!hash_equals($csrfToken, $postedCsrf)) {
            $enrollmentError = 'Invalid CSRF token.';
        } elseif ($postedId <= 0) {
            $enrollmentError = 'Invalid request ID.';
        } elseif ($postedAction === 'approve') {
            if (empty($_POST['confirm_pairing'])) {
                $enrollmentError = 'You must confirm the pairing code matches before approving.';
            } else {
                try {
                    asscmo_approve_enrollment_request(
                        enrollment_admin_pdo(),
                        $postedId,
                        asscmo_enrollment_pepper()
                    );
                    $_SESSION['enrollment_flash'] = ['type' => 'success', 'message' => "Request #{$postedId} approved."];
                    header('Location: /?view=enrollment');
                    exit;
                } catch (AsscmoEnrollmentAlreadyEnrolledException $e) {
                    try {
                        asscmo_deny_enrollment_request(
                            enrollment_admin_pdo(),
                            $postedId,
                            null,
                            'Approval failed: UID already has an active agent_auth entry.'
                        );
                    } catch (Throwable $denyEx) {
                        error_log('ASS-CMO enrollment already-enrolled deny: ' . $denyEx->getMessage());
                    }
                    $_SESSION['enrollment_flash'] = [
                        'type'    => 'warning',
                        'message' => "Request #{$postedId} denied: UID is already enrolled. Remove the existing host auth entry before retrying.",
                    ];
                    header('Location: /?view=enrollment');
                    exit;
                } catch (AsscmoEnrollmentExpiredException $e) {
                    $enrollmentError = "Request #{$postedId} has expired.";
                } catch (AsscmoEnrollmentNotFoundException $e) {
                    $enrollmentError = "Request #{$postedId} not found or already processed.";
                } catch (Throwable $e) {
                    error_log('ASS-CMO enrollment approve UI: ' . $e->getMessage());
                    $enrollmentError = 'Approval failed. Check server logs.';
                }
            }
        } elseif ($postedAction === 'deny') {
            try {
                asscmo_deny_enrollment_request(enrollment_admin_pdo(), $postedId);
                $_SESSION['enrollment_flash'] = ['type' => 'success', 'message' => "Request #{$postedId} denied."];
                header('Location: /?view=enrollment');
                exit;
            } catch (AsscmoEnrollmentExpiredException $e) {
                $enrollmentError = "Request #{$postedId} has expired.";
            } catch (AsscmoEnrollmentNotFoundException $e) {
                $enrollmentError = "Request #{$postedId} not found or already processed.";
            } catch (Throwable $e) {
                error_log('ASS-CMO enrollment deny UI: ' . $e->getMessage());
                $enrollmentError = 'Deny failed. Check server logs.';
            }
        } else {
            $enrollmentError = 'Unknown action.';
        }
    }

    if ($isAgentAuth && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        $postedAction = (string)($_POST['agent_auth_action'] ?? '');
        $postedUid = trim((string)($_POST['uid'] ?? ''));

        if (!hash_equals($csrfToken, $postedCsrf)) {
            $agentAuthError = 'Invalid CSRF token.';
        } elseif ($postedAction !== 'revoke') {
            $agentAuthError = 'Unknown action.';
        } elseif ($postedUid === '' || !preg_match('/^[A-Za-z0-9._:@-]{1,64}$/', $postedUid)) {
            $agentAuthError = 'Invalid UID.';
        } elseif (empty($_POST['confirm_revoke'])) {
            $agentAuthError = 'You must confirm the revoke action.';
        } else {
            try {
                asscmo_revoke_agent_auth(
                    enrollment_admin_pdo(),
                    $postedUid,
                    dashboard_admin_actor(),
                    'Revoked from dashboard agent auth view.'
                );
                $_SESSION['enrollment_flash'] = ['type' => 'success', 'message' => "UID {$postedUid} revoked."];
                header('Location: /?view=agent-auth');
                exit;
            } catch (AsscmoAgentAuthNotFoundException $e) {
                $agentAuthError = 'Agent auth row not found or already revoked.';
            } catch (Throwable $e) {
                error_log('ASS-CMO agent auth revoke UI: ' . $e->getMessage());
                $agentAuthError = 'Revoke failed. Check server logs.';
            }
        }
    }

    if ($isEnrollment) {
        try {
            $enrollmentPdo = enrollment_admin_pdo();
            enrollment_sweep_expired_requests($enrollmentPdo);
            $stmt = $enrollmentPdo->query("
                SELECT id, pairing_code, hostname, fqdn, uid, os_type, agent_version,
                       request_ip::text AS request_ip, created_at, expires_at,
                       (expires_at <= CURRENT_TIMESTAMP) AS is_expired
                  FROM agent_enrollment_requests
                 WHERE status = 'pending'
                 ORDER BY (expires_at <= CURRENT_TIMESTAMP) ASC, created_at ASC
            ");
            $enrollmentRows = $stmt ? $stmt->fetchAll() : [];
        } catch (Throwable $e) {
            error_log('ASS-CMO enrollment load UI: ' . $e->getMessage());
            if ($enrollmentError === '') {
                $enrollmentError = 'Failed to load enrollment requests. Check server logs.';
            }
        }
    } elseif ($isAgentAuth) {
        try {
            $agentAuthStmt = enrollment_admin_pdo()->query("
                SELECT i.hostname, i.fqdn, i.location, i.primary_ipv4_addr,
                       a.uid, a.status, a.last_auth_at, a.last_inventory_at, i.inventory_update_time,
                       a.created_at, a.revoked_at
                  FROM agent_auth a
                  LEFT JOIN inventory i ON i.uid = a.uid
                 ORDER BY
                    CASE
                        WHEN a.revoked_at IS NULL AND a.status IN ('active', 'disabled') THEN 0
                        ELSE 1
                    END,
                    i.hostname NULLS LAST,
                    a.uid
            ");
            $agentAuthRows = $agentAuthStmt ? $agentAuthStmt->fetchAll() : [];
        } catch (Throwable $e) {
            error_log('ASS-CMO agent auth load UI: ' . $e->getMessage());
            if ($agentAuthError === '') {
                $agentAuthError = 'Failed to load agent auth rows. Check server logs.';
            }
        }
    }
} elseif ($currentView === null) {
    $error = 'No dashboard SQL views found in ' . $ctx['views_dir'];
} elseif (!sql_is_reasonably_safe((string)$currentView['sql'])) {
    $error = 'Selected SQL view is not allowed. Only simple SELECT/WITH read-only queries are allowed.';
} else {
    $host = envv('POSTGRES_HOST', 'postgres');
    $port = envv('POSTGRES_PORT', '5432');
    $db   = envv('POSTGRES_DB', 'inventory_db');
    $user = envv('POSTGRES_DASHBOARD_USER', '');
    $pass = envv('POSTGRES_DASHBOARD_PASSWORD', '');

    if ($user === '' || $pass === '') {
        error_log('ASS-CMO dashboard: POSTGRES_DASHBOARD_USER or POSTGRES_DASHBOARD_PASSWORD is not configured.');
        $error = 'Dashboard read-only database credentials are not configured. Check server logs.';
    } else {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec("SET statement_timeout = '5000ms'");
            $stmt = $pdo->query((string)$currentView['sql']);
            $rows = $stmt ? $stmt->fetchAll() : [];
        } catch (Throwable $e) {
            error_log('ASS-CMO dashboard view query UI: ' . $e->getMessage());
            $error = 'Failed to load dashboard view. Check server logs.';
        }
    }
}

$hiddenColumns     = ['notes', 'tags'];
$columns           = ($isEnrollment || $isAgentAuth) ? [] : visible_table_columns($rows, $hiddenColumns);
$copyCommandGroups = build_copy_command_groups($ctx);

$topMetaRenderer = $isEnrollment
    ? function () use ($enrollmentRows): void { ?>
        <div class="meta-box meta-box-compact">
            <div class="meta-row"><span>Pending</span><strong><?= h(count($enrollmentRows)) ?></strong></div>
            <div class="meta-row"><span>Rendered</span><strong><?= h(date('Y-m-d H:i:s')) ?></strong></div>
        </div>
    <?php }
    : ($isAgentAuth
        ? function () use ($agentAuthRows): void { ?>
        <div class="meta-box meta-box-compact">
            <div class="meta-row"><span>Rows</span><strong><?= h(count($agentAuthRows)) ?></strong></div>
            <div class="meta-row"><span>Rendered</span><strong><?= h(date('Y-m-d H:i:s')) ?></strong></div>
        </div>
    <?php }
    : function () use ($rows, $currentView): void { ?>
        <div class="meta-box meta-box-compact">
            <div class="meta-row"><span>Rows</span><strong><?= h(count($rows)) ?></strong></div>
            <div class="meta-row"><span>Source</span><strong><?= h($currentView ? basename((string)$currentView['path']) : '-') ?></strong></div>
            <div class="meta-row"><span>Rendered</span><strong><?= h(date('Y-m-d H:i:s')) ?></strong></div>
        </div>
    <?php });

// ── Render ───────────────────────────────────────────────────────────────────

render_head('ASS-CMO Dashboard', $ctx);
?>
<div class="app app-v2">
    <?php render_brand($ctx); ?>

    <?php render_top(
        (string)($currentView['label'] ?? 'Dashboard'),
        (string)($currentView['description'] ?? ''),
        $topMetaRenderer
    ); ?>

    <?php render_sidebar($ctx, $views, $selectedId, $copyCommandGroups); ?>

    <main class="content">
        <?php if ($isEnrollment): ?>
            <?php render_enrollment_main($enrollmentRows, $enrollmentError, $flashMessage, $csrfToken, $highlightRequestId); ?>
        <?php elseif ($isAgentAuth): ?>
            <?php render_agent_auth_main($agentAuthRows, $agentAuthError, $flashMessage, $csrfToken); ?>
        <?php elseif ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php elseif ($rows === []): ?>
            <div class="empty">No rows returned.</div>
        <?php else: ?>
            <?php render_table_tools(count($rows)); ?>
            <?php render_dashboard_table($rows, $columns, $ctx); ?>
        <?php endif; ?>
    </main>
</div>

<?php render_about_modal(); ?>

<div id="command-launcher" class="command-launcher" hidden>
    <div class="command-launcher-backdrop" data-launcher-close="1"></div>
    <div class="command-launcher-panel" role="dialog" aria-modal="true" aria-label="Command launcher">
        <div class="command-launcher-input-wrap">
            <span class="command-launcher-prefix">⌘K</span>
            <input id="command-launcher-input" class="command-launcher-input" type="search" placeholder="Type hostname, IP, SSH, RDP, WEB action..." autocomplete="off">
        </div>
        <div id="command-launcher-results" class="command-launcher-results"></div>
        <div class="command-launcher-help">Enter runs selected action · ↑/↓ selects · Esc closes</div>
    </div>
</div>

<?php render_theme_script(); ?>

<script src="/assets/dashboard.js?v=<?= h(dashboard_asset_version($ctx)) ?>" defer></script>
<?php render_page_end(); ?>
