<?php
header('Content-Type: text/plain; charset=utf-8');

$includesDir = is_dir('/app/includes') ? '/app/includes' : __DIR__ . '/../includes';

require_once $includesDir . '/common.php';
require_once $includesDir . '/enrollment_auth.php';
require_once __DIR__ . '/functions.php';

$legacyToken = envv('ASSCMO_INVENTORY_TOKEN', '');
$legacySharedTokenEnabled = env_bool('ASSCMO_LEGACY_SHARED_INVENTORY_TOKEN_ENABLED', false);
$host = getenv('POSTGRES_HOST') ?: 'postgres';
$port = getenv('POSTGRES_PORT') ?: '5432';
$dbname = getenv('POSTGRES_DB') ?: 'inventory_db';
$user = getenv('POSTGRES_USER') ?: 'asscmo';
$password = getenv('POSTGRES_PASSWORD') ?: '';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};";

function ingest_bad_request(string $message = "Invalid inventory payload\n") {
    http_response_code(400);
    exit($message);
}

function ingest_forbidden() {
    http_response_code(403);
    exit("Forbidden\n");
}

function ingest_not_configured(string $logMessage) {
    error_log($logMessage);
    http_response_code(500);
    exit("Inventory ingest is not configured.\n");
}

function ingest_header_value(string $serverKey, string $headerName): string {
    $value = $_SERVER[$serverKey] ?? '';
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $headerValue) {
                if (strcasecmp((string)$name, $headerName) === 0 && is_string($headerValue)) {
                    return trim($headerValue);
                }
            }
        }
    }

    return '';
}

function ingest_mark_agent_auth_seen(PDO $pdo, string $uid): void {
    $stmt = $pdo->prepare("
        UPDATE agent_auth
           SET last_auth_at = CURRENT_TIMESTAMP,
               updated_at = CURRENT_TIMESTAMP
         WHERE uid = :uid
    ");
    $stmt->execute([':uid' => $uid]);
}

function ingest_mark_agent_inventory_seen(PDO $pdo, string $uid): void {
    $stmt = $pdo->prepare("
        UPDATE agent_auth
           SET last_inventory_at = CURRENT_TIMESTAMP,
               updated_at = CURRENT_TIMESTAMP
         WHERE uid = :uid
    ");
    $stmt->execute([':uid' => $uid]);
}

function ingest_authenticate_agent_secret(PDO $pdo, string $uid, string $providedAgentSecret): string {
    try {
        $pepper = asscmo_enrollment_pepper();
    } catch (Throwable $e) {
        ingest_not_configured('ASS-CMO inventory ingest is not configured: enrollment pepper is missing or empty');
    }

    $stmt = $pdo->prepare("
        SELECT uid, agent_secret_hash, agent_secret_hash_algorithm, status, disabled_at, revoked_at
          FROM agent_auth
         WHERE uid = :uid
    ");
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row) || ($row['status'] ?? '') !== 'active') {
        ingest_forbidden();
    }

    if (($row['disabled_at'] ?? null) !== null || ($row['revoked_at'] ?? null) !== null) {
        ingest_forbidden();
    }

    if (($row['agent_secret_hash_algorithm'] ?? '') !== 'hmac-sha256') {
        ingest_forbidden();
    }

    if (!asscmo_verify_agent_secret($providedAgentSecret, (string)$row['agent_secret_hash'], $pepper)) {
        ingest_forbidden();
    }

    ingest_mark_agent_auth_seen($pdo, $uid);
    return 'agent_secret';
}

function ingest_is_placeholder_legacy_token(string $token): bool {
    $normalized = strtolower(trim($token));

    return in_array($normalized, [
        'change-me',
        'changeme',
        'change_this',
        'change-this',
        'change-this-token',
        'generated-token',
    ], true);
}

function ingest_reject_disabled_or_revoked_legacy_uid(PDO $pdo, string $uid): void {
    $stmt = $pdo->prepare("
        SELECT status, disabled_at, revoked_at
          FROM agent_auth
         WHERE uid = :uid
         LIMIT 1
    ");
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return;
    }

    $status = (string)($row['status'] ?? '');
    if (
        $status === 'disabled'
        || $status === 'revoked'
        || ($row['disabled_at'] ?? null) !== null
        || ($row['revoked_at'] ?? null) !== null
    ) {
        ingest_forbidden();
    }
}

function ingest_authenticate_legacy_shared_token(PDO $pdo, string $uid, bool $legacySharedTokenEnabled, string $legacyToken): string {
    if (!$legacySharedTokenEnabled) {
        ingest_forbidden();
    }

    if (trim($legacyToken) === '') {
        ingest_not_configured('ASS-CMO legacy inventory ingest is enabled but ASSCMO_INVENTORY_TOKEN is missing or empty');
    }

    if (ingest_is_placeholder_legacy_token($legacyToken)) {
        ingest_not_configured('ASS-CMO legacy inventory ingest is enabled but ASSCMO_INVENTORY_TOKEN still contains a placeholder value');
    }

    $providedToken = ingest_header_value('HTTP_X_INVENTORY_TOKEN', 'X-Inventory-Token');
    if (!hash_equals(trim($legacyToken), $providedToken)) {
        ingest_forbidden();
    }

    ingest_reject_disabled_or_revoked_legacy_uid($pdo, $uid);

    return 'legacy_shared_token';
}

function ingest_authenticate_inventory(PDO $pdo, string $uid, bool $legacySharedTokenEnabled, string $legacyToken): string {
    $providedAgentSecret = ingest_header_value('HTTP_X_AGENT_SECRET', 'X-Agent-Secret');
    if ($providedAgentSecret !== '') {
        return ingest_authenticate_agent_secret($pdo, $uid, $providedAgentSecret);
    }

    return ingest_authenticate_legacy_shared_token($pdo, $uid, $legacySharedTokenEnabled, $legacyToken);
}

function ingest_clean_string(string $value, int $maxLength): string {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

function ingest_optional_string(array $data, string $key, int $maxLength, ?string $default = null): ?string {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return $default;
    }

    if (!is_scalar($data[$key])) {
        return $default;
    }

    $value = ingest_clean_string((string)$data[$key], $maxLength);
    return $value !== '' ? $value : $default;
}

function ingest_required_uid(array $data): string {
    $uid = ingest_optional_string($data, 'uid', 64);

    if ($uid === null || !preg_match('/^[A-Za-z0-9._:@-]{1,64}$/', $uid)) {
        ingest_bad_request("Invalid JSON data or missing UID.\n");
    }

    return $uid;
}

function ingest_optional_ip(array $data, string $key, int $flags): ?string {
    $value = ingest_optional_string($data, $key, 128);

    if ($value === null) {
        return null;
    }

    return filter_var($value, FILTER_VALIDATE_IP, $flags) !== false ? $value : null;
}

function ingest_optional_int(array $data, string $key, int $min, int $max, int $default = 0): int {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return $default;
    }

    if (!is_numeric($data[$key])) {
        return $default;
    }

    $value = (int)$data[$key];
    return max($min, min($max, $value));
}

function ingest_optional_float(array $data, string $key, float $min, float $max, float $default = 0.0): float {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return $default;
    }

    if (!is_numeric($data[$key])) {
        return $default;
    }

    $value = (float)$data[$key];
    return max($min, min($max, $value));
}

function ingest_optional_bool(array $data, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $data)) {
        return $default;
    }

    return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function ingest_string_array(array $data, string $key, int $maxItems, int $maxLength): array {
    if (!isset($data[$key]) || !is_array($data[$key])) {
        return [];
    }

    $out = [];

    foreach ($data[$key] as $value) {
        if (count($out) >= $maxItems) {
            break;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $clean = ingest_clean_string((string)$value, $maxLength);

        if ($clean !== '') {
            $out[] = $clean;
        }
    }

    return array_values(array_unique($out));
}

function ingest_ip_array(array $data, string $key, int $flags, int $maxItems): array {
    if (!isset($data[$key]) || !is_array($data[$key])) {
        return [];
    }

    $out = [];

    foreach ($data[$key] as $value) {
        if (count($out) >= $maxItems) {
            break;
        }

        if (!is_scalar($value)) {
            continue;
        }

        $clean = ingest_clean_string((string)$value, 128);

        if (filter_var($clean, FILTER_VALIDATE_IP, $flags) !== false) {
            $out[] = $clean;
        }
    }

    return array_values(array_unique($out));
}

function ingest_port_array(array $data, string $key, int $maxItems): array {
    if (!isset($data[$key]) || !is_array($data[$key])) {
        return [];
    }

    $out = [];

    foreach ($data[$key] as $value) {
        if (count($out) >= $maxItems) {
            break;
        }

        if (!is_numeric($value)) {
            continue;
        }

        $port = (int)$value;

        if ($port >= 1 && $port <= 65535) {
            $out[] = $port;
        }
    }

    return array_values(array_unique($out));
}

function normalize_inventory_payload(array $data): array {
    $uid = ingest_required_uid($data);

    return [
        'uid' => $uid,
        'hostname' => ingest_optional_string($data, 'hostname', 255, $uid),
        'fqdn' => ingest_optional_string($data, 'fqdn', 255),
        'primary_interface' => ingest_optional_string($data, 'primary_interface', 50),
        'primary_ipv4_addr' => ingest_optional_ip($data, 'primary_ipv4_addr', FILTER_FLAG_IPV4),
        'ipv4_gateway' => ingest_optional_ip($data, 'ipv4_gateway', FILTER_FLAG_IPV4),
        'primary_ipv6_addr' => ingest_optional_ip($data, 'primary_ipv6_addr', FILTER_FLAG_IPV6),
        'ipv6_gateway' => ingest_optional_ip($data, 'ipv6_gateway', FILTER_FLAG_IPV6),
        'dns_servers' => ingest_ip_array($data, 'dns_servers', FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6, 16),
        'all_ipv4_addr' => ingest_ip_array($data, 'all_ipv4_addr', FILTER_FLAG_IPV4, 64),
        'all_ipv6_addr' => ingest_ip_array($data, 'all_ipv6_addr', FILTER_FLAG_IPV6, 64),
        'location' => ingest_optional_string($data, 'location', 100, 'unknown'),
        'network_segment' => ingest_optional_string($data, 'network_segment', 100, 'unknown'),
        'listening_ports' => ingest_port_array($data, 'listening_ports', 512),
        'os_name' => ingest_optional_string($data, 'os_name', 255, 'Unknown'),
        'os_type' => ingest_optional_string($data, 'os_type', 50, 'unknown'),
        'kernel_version' => ingest_optional_string($data, 'kernel_version', 255),
        'reboot_required' => ingest_optional_bool($data, 'reboot_required'),
        'pending_updates_count' => ingest_optional_int($data, 'pending_updates_count', 0, 100000),
        'cpu_model' => ingest_optional_string($data, 'cpu_model', 255),
        'cpu_cores' => ingest_optional_int($data, 'cpu_cores', 0, 4096),
        'cpu_architecture' => ingest_optional_string($data, 'cpu_architecture', 20),
        'ram_gb' => ingest_optional_float($data, 'ram_gb', 0, 1000000),
        'disk_total_gb' => ingest_optional_float($data, 'disk_total_gb', 0, 1000000000),
        'disk_used_gb' => ingest_optional_float($data, 'disk_used_gb', 0, 1000000000),
        'disk_free_gb' => ingest_optional_float($data, 'disk_free_gb', 0, 1000000000),
        'disk_usage_percent' => ingest_optional_int($data, 'disk_usage_percent', 0, 100),
        'docker_installed' => ingest_optional_bool($data, 'docker_installed'),
        'docker_version' => ingest_optional_string($data, 'docker_version', 50),
        'admin_access' => ingest_string_array($data, 'admin_access', 128, 128),
        'uptime_seconds' => ingest_optional_int($data, 'uptime_seconds', 0, 2147483647),
        'system_boot_time' => ingest_optional_string($data, 'system_boot_time', 64),
        'system_upgrade_time' => ingest_optional_string($data, 'system_upgrade_time', 64),
        'agent_name' => ingest_optional_string($data, 'agent_name', 64),
        'agent_version' => ingest_optional_string($data, 'agent_version', 64),
        'agent_channel' => ingest_optional_string($data, 'agent_channel', 64),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit("Method Not Allowed\n");
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!preg_match('#^\\s*application/json\\s*(;|$)#i', $contentType)) {
    http_response_code(415);
    exit("Unsupported Media Type\n");
}

$maxBodyBytes = 1024 * 1024;
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;

if ($contentLength > $maxBodyBytes) {
    http_response_code(413);
    exit("Payload Too Large\n");
}

$input = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);

if ($input === false || strlen($input) > $maxBodyBytes) {
    http_response_code(413);
    exit("Payload Too Large\n");
}

$data = json_decode($input, true);

if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    exit("Invalid JSON\n");
}

$data = normalize_inventory_payload($data);

if ($data && isset($data['uid'])) {
    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $authMethod = ingest_authenticate_inventory($pdo, $data['uid'], $legacySharedTokenEnabled, $legacyToken);
        $loc_data = get_location_info($data['primary_ipv4_addr'] ?? '');
        $final_loc = (empty($data['location']) || $data['location'] === 'unknown') ? $loc_data['loc'] : $data['location'];
        $final_seg = (empty($data['network_segment']) || $data['network_segment'] === 'unknown') ? $loc_data['seg'] : $data['network_segment'];

        $sql = "INSERT INTO inventory (
            uid, hostname, fqdn, primary_interface, primary_ipv4_addr, ipv4_gateway, primary_ipv6_addr, ipv6_gateway,
            dns_servers, all_ipv4_addr, all_ipv6_addr, location, network_segment, listening_ports, os_name, os_type,
            kernel_version, reboot_required, pending_updates_count, cpu_model, cpu_cores, cpu_architecture, ram_gb,
            disk_total_gb, disk_used_gb, disk_free_gb, disk_usage_percent, docker_installed, docker_version,
            admin_access, uptime_seconds, system_boot_time, system_upgrade_time, inventory_update_time, agent_name, agent_version, agent_channel, agent_update_time
        ) VALUES (
            :uid, :hn, :fqdn, :p_if, :ip4, :gw4, :ip6, :gw6, :dns, :all4, :all6, :loc, :seg, :ports, :os, :ost,
            :kernel, :reboot, :upd_c, :cpu_m, :cores, :arch, :ram, :d_tot, :d_used, :d_free, :d_perc, :dock_i,
            :dock_v, :admins, :upt_s, :boot, :upg, CURRENT_TIMESTAMP(0),
            :agent_name, :agent_version, :agent_channel, CASE
        WHEN NULLIF(:agent_version_check, '') IS NOT NULL THEN CURRENT_TIMESTAMP(0)
        ELSE NULL
      END
        ) ON CONFLICT (uid) DO UPDATE SET
            hostname = EXCLUDED.hostname,
            fqdn = EXCLUDED.fqdn,
            primary_interface = EXCLUDED.primary_interface,
            primary_ipv4_addr = EXCLUDED.primary_ipv4_addr,
            ipv4_gateway = EXCLUDED.ipv4_gateway,
            primary_ipv6_addr = EXCLUDED.primary_ipv6_addr,
            ipv6_gateway = EXCLUDED.ipv6_gateway,
            dns_servers = EXCLUDED.dns_servers,
            all_ipv4_addr = EXCLUDED.all_ipv4_addr,
            all_ipv6_addr = EXCLUDED.all_ipv6_addr,
            location = EXCLUDED.location, network_segment = EXCLUDED.network_segment,
            listening_ports = EXCLUDED.listening_ports,
            os_name = EXCLUDED.os_name, os_type = EXCLUDED.os_type,
            kernel_version = EXCLUDED.kernel_version,
            reboot_required = EXCLUDED.reboot_required,
            pending_updates_count = EXCLUDED.pending_updates_count,
            cpu_model = EXCLUDED.cpu_model,
            cpu_cores = EXCLUDED.cpu_cores,
            cpu_architecture = EXCLUDED.cpu_architecture,
            ram_gb = EXCLUDED.ram_gb,
            disk_total_gb = EXCLUDED.disk_total_gb,
            disk_used_gb = EXCLUDED.disk_used_gb,
            disk_free_gb = EXCLUDED.disk_free_gb,
            disk_usage_percent = EXCLUDED.disk_usage_percent,
            docker_installed = EXCLUDED.docker_installed,
            docker_version = EXCLUDED.docker_version,
            admin_access = EXCLUDED.admin_access,
            uptime_seconds = EXCLUDED.uptime_seconds,
            system_boot_time = EXCLUDED.system_boot_time,
            system_upgrade_time = EXCLUDED.system_upgrade_time,
            inventory_update_time = CURRENT_TIMESTAMP(0),
            agent_name = EXCLUDED.agent_name,
            agent_version = EXCLUDED.agent_version,
            agent_channel = EXCLUDED.agent_channel,
            agent_update_time = CASE
      WHEN inventory.agent_version IS DISTINCT FROM EXCLUDED.agent_version THEN CURRENT_TIMESTAMP(0)
      ELSE inventory.agent_update_time
    END";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid'    => $data['uid'],
            ':hn'     => $data['hostname'],
            ':fqdn'   => $data['fqdn'] ?? null,
            ':p_if'   => $data['primary_interface'] ?? null,
            ':ip4'    => $data['primary_ipv4_addr'] ?? null,
            ':gw4'    => $data['ipv4_gateway'] ?? null,
            ':ip6'    => $data['primary_ipv6_addr'] ?? null,
            ':gw6'    => $data['ipv6_gateway'] ?? null,
            ':dns'    => json_encode($data['dns_servers'] ?? []),
            ':all4'   => json_encode($data['all_ipv4_addr'] ?? []),
            ':all6'   => json_encode($data['all_ipv6_addr'] ?? []),
            ':loc'    => $final_loc,
            ':seg'    => $final_seg,
            ':ports'  => json_encode($data['listening_ports'] ?? []),
            ':os'     => $data['os_name'] ?? 'Unknown',
            ':ost'    => $data['os_type'] ?? 'unknown',
            ':kernel' => $data['kernel_version'] ?? null,
            ':reboot' => ($data['reboot_required'] ?? false) ? 'true' : 'false',
            ':upd_c'  => (int)($data['pending_updates_count'] ?? 0),
            ':cpu_m'  => $data['cpu_model'] ?? null,
            ':cores'  => (int)($data['cpu_cores'] ?? 0),
            ':arch'   => $data['cpu_architecture'] ?? null,
            ':ram'    => (float)($data['ram_gb'] ?? 0),
            ':d_tot'  => (float)($data['disk_total_gb'] ?? 0),
            ':d_used' => (float)($data['disk_used_gb'] ?? 0),
            ':d_free' => (float)($data['disk_free_gb'] ?? 0),
            ':d_perc' => (int)($data['disk_usage_percent'] ?? 0),
            ':dock_i' => ($data['docker_installed'] ?? false) ? 'true' : 'false',
            ':dock_v' => $data['docker_version'] ?? null,
            ':admins' => json_encode($data['admin_access'] ?? []),
            ':upt_s'  => (int)($data['uptime_seconds'] ?? 0),
            ':boot'   => $data['system_boot_time'] ?? null,
            ':upg'    => $data['system_upgrade_time'] ?? null,
            ':agent_name'        => $data['agent_name'] ?? null,
            ':agent_version'     => $data['agent_version'] ?? null,
            ':agent_version_check' => $data['agent_version'] ?? null,
            ':agent_channel'     => $data['agent_channel'] ?? null,
        ]);
        if ($authMethod === 'agent_secret') {
            ingest_mark_agent_inventory_seen($pdo, $data['uid']);
        }
        echo "OK - Inventory updated for UID: " . htmlspecialchars((string)$data['uid'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "\n";
    } catch (Throwable $e) {
        error_log('ASS-CMO inventory DB error: ' . $e->getMessage());
        http_response_code(500);
        echo "Database error\n";
    }
} else {
    http_response_code(400);
    echo "Invalid JSON data or missing UID.\n";
}
