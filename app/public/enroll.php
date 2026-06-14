<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

$includesDir = is_dir('/app/includes') ? '/app/includes' : __DIR__ . '/../includes';

require_once $includesDir . '/common.php';
require_once $includesDir . '/enrollment_auth.php';

function enroll_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo "\n";
    exit;
}

function enroll_error(int $statusCode, string $message): void {
    enroll_json_response(['error' => $message], $statusCode);
}

function enroll_clean_string(string $value, int $maxLength): string {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

function enroll_optional_string(array $data, string $key, int $maxLength): ?string {
    if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
        return null;
    }

    if (!is_scalar($data[$key])) {
        return null;
    }

    $value = enroll_clean_string((string)$data[$key], $maxLength);
    return $value !== '' ? $value : null;
}

function enroll_request_ip(): string {
    $requestIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if (filter_var($requestIp, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('Cannot resolve enrollment request IP.');
    }

    return $requestIp;
}

function enroll_authorization_header(): string {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }
    }

    return '';
}

function enroll_poll_token_header(): string {
    $value = $_SERVER['HTTP_X_POLL_TOKEN'] ?? '';
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'X-Poll-Token') === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }
    }

    return '';
}

function enroll_require_approve_authorization(): void {
    $expectedToken = trim(envv('ASSCMO_ENROLLMENT_APPROVE_TOKEN', ''));
    if ($expectedToken === '' || $expectedToken === 'change-this-enrollment-approve-token') {
        enroll_error(403, 'Forbidden');
    }

    $authorization = enroll_authorization_header();
    if (!preg_match('/^Bearer\s+(.+)\z/i', $authorization, $matches)) {
        enroll_error(403, 'Forbidden');
    }

    $providedToken = trim($matches[1]);
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        enroll_error(403, 'Forbidden');
    }
}

function enroll_expire_request(PDO $pdo, int $requestId, string $status): void {
    if ($status === 'pending') {
        $stmt = $pdo->prepare("
            UPDATE agent_enrollment_requests
               SET status = 'expired',
                   expired_reason = 'timeout'
             WHERE id = :request_id AND status = 'pending'
        ");
        $stmt->execute([':request_id' => $requestId]);
        return;
    }

    if ($status === 'approved') {
        asscmo_expire_approved_enrollment_and_cleanup_orphan($pdo, $requestId);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'poll') {
    $requestIdRaw = $_GET['request_id'] ?? '';
    if (!ctype_digit($requestIdRaw)) {
        enroll_error(400, 'Missing or invalid request_id');
    }
    $requestId = (int)$requestIdRaw;

    $pollTokenRaw = enroll_poll_token_header();
    if ($pollTokenRaw === '') {
        enroll_error(400, 'Missing poll_token');
    }

    try {
        $pepper = asscmo_enrollment_pepper();
        $host = envv('POSTGRES_HOST', 'postgres');
        $port = envv('POSTGRES_PORT', '5432');
        $db = envv('POSTGRES_DB', 'inventory_db');
        $user = envv('POSTGRES_USER', 'asscmo');
        $password = envv('POSTGRES_PASSWORD', '');
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $stmt = $pdo->prepare("
            SELECT status, poll_token_hash,
                GREATEST(0, EXTRACT(EPOCH FROM (expires_at - CURRENT_TIMESTAMP))::int) AS expires_in
              FROM agent_enrollment_requests
             WHERE id = :request_id
        ");
        $stmt->execute([':request_id' => $requestId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            enroll_error(404, 'Not found');
        }

        $status = $row['status'];

        if ($status === 'expired' || $status === 'used') {
            enroll_error(404, 'Not found');
        }

        $computedHash = asscmo_hmac_sha256($pollTokenRaw, $pepper);
        if (!asscmo_hash_equals($computedHash, $row['poll_token_hash'])) {
            enroll_error(403, 'Forbidden');
        }

        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare("
            SELECT status, agent_secret_once,
                   (expires_at <= CURRENT_TIMESTAMP) AS is_expired,
                   GREATEST(0, EXTRACT(EPOCH FROM (expires_at - CURRENT_TIMESTAMP))::int) AS expires_in
              FROM agent_enrollment_requests
             WHERE id = :request_id
               FOR UPDATE
        ");
        $lockStmt->execute([':request_id' => $requestId]);
        $lockRow = $lockStmt->fetch();
        if (!is_array($lockRow)) {
            $pdo->rollBack();
            enroll_error(404, 'Not found');
        }

        $status = (string)$lockRow['status'];
        if ($status === 'expired' || $status === 'used') {
            $pdo->rollBack();
            enroll_error(404, 'Not found');
        }

        if ((bool)$lockRow['is_expired'] && ($status === 'pending' || $status === 'approved')) {
            enroll_expire_request($pdo, $requestId, $status);
            $pdo->commit();
            enroll_error(404, 'Not found');
        }

        if ($status === 'pending') {
            $expiresIn = (int)$lockRow['expires_in'];
            $pdo->commit();
            enroll_json_response([
                'status' => 'pending',
                'expires_in' => $expiresIn,
            ]);
        }

        if ($status === 'denied') {
            $pdo->commit();
            enroll_json_response(['status' => 'denied']);
        }

        if ($status === 'approved') {
            $agentSecretOnce = $lockRow['agent_secret_once'];
            if ($agentSecretOnce === null) {
                $pdo->commit();
                enroll_json_response(['status' => 'approved', 'secret_delivered' => true]);
            }
            $deliverStmt = $pdo->prepare("
                UPDATE agent_enrollment_requests
                   SET status = 'used',
                       used_at = CURRENT_TIMESTAMP,
                       last_poll_at = CURRENT_TIMESTAMP,
                       agent_secret_once = NULL
                 WHERE id = :request_id AND status = 'approved'
            ");
            $deliverStmt->execute([':request_id' => $requestId]);
            $pdo->commit();
            enroll_json_response([
                'status' => 'approved',
                'agent_secret' => $agentSecretOnce,
            ]);
        }

        $pdo->rollBack();
        enroll_error(404, 'Not found');

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ASS-CMO enrollment poll error: ' . $e->getMessage());
        enroll_error(500, 'Enrollment poll failed');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: GET, POST');
    enroll_error(405, 'Method Not Allowed');
}

if (($_GET['action'] ?? '') === 'approve') {
    enroll_require_approve_authorization();
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!preg_match('#^\\s*application/json\\s*(;|$)#i', $contentType)) {
    enroll_error(415, 'Unsupported Media Type');
}

$maxBodyBytes = 64 * 1024;
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;

if ($contentLength > $maxBodyBytes) {
    enroll_error(413, 'Payload Too Large');
}

$input = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);

if ($input === false || strlen($input) > $maxBodyBytes) {
    enroll_error(413, 'Payload Too Large');
}

$data = json_decode($input, true);

if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    enroll_error(400, 'Invalid JSON');
}

if (($_GET['action'] ?? '') === 'approve') {
    $requestId = $data['request_id'] ?? null;
    if (!is_int($requestId) || $requestId <= 0) {
        enroll_error(400, 'Missing or invalid request_id');
    }

    try {
        $pepper = asscmo_enrollment_pepper();
        $host = envv('POSTGRES_HOST', 'postgres');
        $port = envv('POSTGRES_PORT', '5432');
        $db = envv('POSTGRES_DB', 'inventory_db');
        $user = envv('POSTGRES_USER', 'asscmo');
        $password = envv('POSTGRES_PASSWORD', '');
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        asscmo_approve_enrollment_request($pdo, $requestId, $pepper);

        enroll_json_response([
            'status' => 'approved',
            'request_id' => $requestId,
        ]);

    } catch (AsscmoEnrollmentAlreadyEnrolledException $e) {
        enroll_error(409, 'UID already enrolled');
    } catch (AsscmoEnrollmentExpiredException $e) {
        enroll_error(410, 'Gone');
    } catch (AsscmoEnrollmentNotFoundException $e) {
        enroll_error(404, 'Not found');
    } catch (Throwable $e) {
        error_log('ASS-CMO enrollment approve error: ' . $e->getMessage());
        enroll_error(500, 'Enrollment approve failed');
    }
}

$hostname = enroll_optional_string($data, 'hostname', 255);

if ($hostname === null) {
    enroll_error(400, 'Missing or invalid hostname');
}

$uid = enroll_optional_string($data, 'uid', 64);
if ($uid === null || !preg_match('/^[A-Za-z0-9._:@-]{1,64}$/', $uid)) {
    enroll_error(400, 'Missing or invalid uid');
}

$fqdn = enroll_optional_string($data, 'fqdn', 255);
$osType = enroll_optional_string($data, 'os_type', 50);
$agentVersion = enroll_optional_string($data, 'agent_version', 64);
$pollInterval = 5;

try {
    $pepper = asscmo_enrollment_pepper();
    $requestIp = enroll_request_ip();
    $pairingCode = asscmo_generate_pairing_code();
    $pollToken = asscmo_generate_poll_token();
    $pairingCodeHash = asscmo_hash_pairing_code($pairingCode, $pepper);
    $pollTokenHash = asscmo_hmac_sha256($pollToken, $pepper);

    $host = envv('POSTGRES_HOST', 'postgres');
    $port = envv('POSTGRES_PORT', '5432');
    $db = envv('POSTGRES_DB', 'inventory_db');
    $user = envv('POSTGRES_USER', 'asscmo');
    $password = envv('POSTGRES_PASSWORD', '');
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->beginTransaction();

    $expireStmt = $pdo->prepare("
        UPDATE agent_enrollment_requests
           SET status = 'expired',
               expired_reason = 'replaced_by_new_request'
         WHERE request_ip = CAST(:request_ip AS inet)
           AND lower(hostname) = lower(:hostname)
           AND status = 'pending'
    ");
    $expireStmt->execute([
        ':request_ip' => $requestIp,
        ':hostname' => $hostname,
    ]);

    $insertStmt = $pdo->prepare("
        INSERT INTO agent_enrollment_requests (
            uid,
            hostname,
            fqdn,
            os_type,
            agent_version,
            request_ip,
            pairing_code,
            pairing_code_hash,
            poll_token_hash
        ) VALUES (
            :uid,
            :hostname,
            :fqdn,
            :os_type,
            :agent_version,
            CAST(:request_ip AS inet),
            :pairing_code,
            :pairing_code_hash,
            :poll_token_hash
        )
        RETURNING id,
            GREATEST(0, EXTRACT(EPOCH FROM (expires_at - CURRENT_TIMESTAMP))::int) AS expires_in
    ");
    $insertStmt->execute([
        ':uid' => $uid,
        ':hostname' => $hostname,
        ':fqdn' => $fqdn,
        ':os_type' => $osType,
        ':agent_version' => $agentVersion,
        ':request_ip' => $requestIp,
        ':pairing_code' => $pairingCode,        // display-only; not an auth factor
        ':pairing_code_hash' => $pairingCodeHash,
        ':poll_token_hash' => $pollTokenHash,
    ]);

    $row = $insertStmt->fetch();
    if (!is_array($row) || !isset($row['id'], $row['expires_in'])) {
        throw new RuntimeException('Enrollment request insert did not return an id.');
    }

    $pdo->commit();

    $requestId = (int)$row['id'];
    $baseUrl   = rtrim(envv('ASSCMO_BASE_URL', ''), '/');

    $response = [
        'request_id'       => $requestId,
        'poll_token'       => $pollToken,
        'pairing_code'     => $pairingCode,
        'expires_in'       => (int)$row['expires_in'],
        'poll_interval'    => $pollInterval,
    ];
    if ($baseUrl !== '') {
        $response['verification_url'] = $baseUrl . '/?view=enrollment&request_id=' . $requestId;
    }

    enroll_json_response($response);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('ASS-CMO enrollment start error: ' . $e->getMessage());
    enroll_error(500, 'Enrollment start failed');
}
