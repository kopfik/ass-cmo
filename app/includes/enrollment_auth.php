<?php
declare(strict_types=1);

function asscmo_generate_pairing_code(int $letterCount = 3, int $digitCount = 3): string {
    if ($letterCount < 1 || $digitCount < 1) {
        throw new InvalidArgumentException('Pairing code groups must be at least one character.');
    }

    $letters = '';
    for ($i = 0; $i < $letterCount; $i++) {
        $letters .= chr(random_int(ord('A'), ord('Z')));
    }

    $digits = '';
    for ($i = 0; $i < $digitCount; $i++) {
        $digits .= (string)random_int(0, 9);
    }

    return $letters . '-' . $digits;
}

function asscmo_normalize_pairing_code(string $code): string {
    $normalized = strtoupper(trim($code));
    return preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';
}

function asscmo_hmac_sha256(string $value, string $pepper): string {
    if ($pepper === '') {
        throw new InvalidArgumentException('HMAC pepper must not be empty.');
    }

    return hash_hmac('sha256', $value, $pepper);
}

function asscmo_hash_pairing_code(string $code, string $pepper): string {
    $normalized = asscmo_normalize_pairing_code($code);

    if ($normalized === '') {
        throw new InvalidArgumentException('Pairing code must not be empty.');
    }

    return asscmo_hmac_sha256($normalized, $pepper);
}

function asscmo_generate_poll_token(int $bytes = 32): string {
    if ($bytes < 32) {
        throw new InvalidArgumentException('Poll tokens must be at least 32 random bytes.');
    }

    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function asscmo_generate_agent_secret(int $bytes = 32): string {
    if ($bytes < 32) {
        throw new InvalidArgumentException('Agent secrets must be at least 32 random bytes.');
    }

    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function asscmo_hash_agent_secret(string $agentSecret, string $pepper): string {
    if ($agentSecret === '') {
        throw new InvalidArgumentException('Agent secret must not be empty.');
    }

    return asscmo_hmac_sha256($agentSecret, $pepper);
}

function asscmo_hash_equals(string $knownHash, string $providedHash): bool {
    return hash_equals($knownHash, $providedHash);
}

// Retained for legacy/reference only. Not used in the current visual-confirmation
// UX where the pairing code is stored in plain text and displayed in the admin UI
// for visual match; no admin typing or server-side code verification is required.
function asscmo_verify_pairing_code(string $code, string $knownHash, string $pepper): bool {
    return asscmo_hash_equals($knownHash, asscmo_hash_pairing_code($code, $pepper));
}

function asscmo_verify_agent_secret(string $agentSecret, string $knownHash, string $pepper): bool {
    return asscmo_hash_equals($knownHash, asscmo_hash_agent_secret($agentSecret, $pepper));
}

class AsscmoEnrollmentNotFoundException extends RuntimeException {}
class AsscmoEnrollmentExpiredException extends RuntimeException {}
class AsscmoEnrollmentAlreadyEnrolledException extends RuntimeException {}
class AsscmoAgentAuthNotFoundException extends RuntimeException {}

function asscmo_archive_revoked_agent_auth_for_reenrollment(PDO $pdo, string $uid, int $replacementRequestId): bool {
    if ($uid === '') {
        throw new InvalidArgumentException('UID must not be empty.');
    }

    $stmt = $pdo->prepare("
        WITH moved AS (
            DELETE FROM agent_auth
             WHERE uid = :uid
               AND (status = 'revoked' OR revoked_at IS NOT NULL)
         RETURNING
            uid,
            agent_secret_hash,
            agent_secret_hash_algorithm,
            secret_issued_at,
            secret_last_rotated_at,
            created_from_enrollment_request_id,
            status,
            created_at,
            updated_at,
            last_auth_at,
            last_inventory_at,
            disabled_at,
            disabled_by,
            disabled_reason,
            revoked_at,
            revoked_by,
            revoked_reason
        )
        INSERT INTO agent_auth_history (
            uid,
            agent_secret_hash,
            agent_secret_hash_algorithm,
            secret_issued_at,
            secret_last_rotated_at,
            created_from_enrollment_request_id,
            replacement_enrollment_request_id,
            status,
            created_at,
            updated_at,
            last_auth_at,
            last_inventory_at,
            disabled_at,
            disabled_by,
            disabled_reason,
            revoked_at,
            revoked_by,
            revoked_reason,
            archived_reason
        )
        SELECT
            uid,
            agent_secret_hash,
            agent_secret_hash_algorithm,
            secret_issued_at,
            secret_last_rotated_at,
            created_from_enrollment_request_id,
            :replacement_request_id,
            status,
            created_at,
            updated_at,
            last_auth_at,
            last_inventory_at,
            disabled_at,
            disabled_by,
            disabled_reason,
            revoked_at,
            revoked_by,
            revoked_reason,
            'replaced_by_fresh_reenrollment'
          FROM moved
    ");
    $stmt->execute([
        ':uid' => $uid,
        ':replacement_request_id' => $replacementRequestId,
    ]);

    return $stmt->rowCount() === 1;
}

function asscmo_expire_approved_enrollment_and_cleanup_orphan(PDO $pdo, ?int $requestId = null): void {
    $requestFilter = $requestId !== null ? 'AND id = :request_id' : '';
    $stmt = $pdo->prepare("
        WITH expired_requests AS (
            UPDATE agent_enrollment_requests
               SET status = 'expired',
                   expired_reason = 'approval_not_collected',
                   agent_secret_once = NULL
             WHERE status = 'approved'
               AND expires_at <= CURRENT_TIMESTAMP
               AND used_at IS NULL
               AND agent_secret_once IS NOT NULL
               {$requestFilter}
         RETURNING id
        ),
        moved AS (
            DELETE FROM agent_auth
              USING expired_requests
             WHERE agent_auth.created_from_enrollment_request_id = expired_requests.id
               AND agent_auth.status = 'active'
               AND agent_auth.last_auth_at IS NULL
               AND agent_auth.last_inventory_at IS NULL
         RETURNING
            agent_auth.uid,
            agent_auth.agent_secret_hash,
            agent_auth.agent_secret_hash_algorithm,
            agent_auth.secret_issued_at,
            agent_auth.secret_last_rotated_at,
            agent_auth.created_from_enrollment_request_id,
            agent_auth.status,
            agent_auth.created_at,
            agent_auth.updated_at,
            agent_auth.last_auth_at,
            agent_auth.last_inventory_at,
            agent_auth.disabled_at,
            agent_auth.disabled_by,
            agent_auth.disabled_reason,
            agent_auth.revoked_at,
            agent_auth.revoked_by,
            agent_auth.revoked_reason
        )
        INSERT INTO agent_auth_history (
            uid,
            agent_secret_hash,
            agent_secret_hash_algorithm,
            secret_issued_at,
            secret_last_rotated_at,
            created_from_enrollment_request_id,
            replacement_enrollment_request_id,
            status,
            created_at,
            updated_at,
            last_auth_at,
            last_inventory_at,
            disabled_at,
            disabled_by,
            disabled_reason,
            revoked_at,
            revoked_by,
            revoked_reason,
            archived_reason
        )
        SELECT
            uid,
            agent_secret_hash,
            agent_secret_hash_algorithm,
            secret_issued_at,
            secret_last_rotated_at,
            created_from_enrollment_request_id,
            NULL,
            status,
            created_at,
            updated_at,
            last_auth_at,
            last_inventory_at,
            disabled_at,
            disabled_by,
            disabled_reason,
            revoked_at,
            revoked_by,
            revoked_reason,
            'approval_not_collected'
          FROM moved
    ");
    $params = [];
    if ($requestId !== null) {
        $params[':request_id'] = $requestId;
    }
    $stmt->execute($params);
}

/**
 * Approves a pending enrollment request and creates the corresponding agent_auth row.
 *
 * Manages its own transaction; rolls back internally on DB failure.
 * Throws AsscmoEnrollmentNotFoundException (→ 404) or AsscmoEnrollmentExpiredException (→ 410)
 * before any transaction begins. Throws RuntimeException on unexpected DB errors.
 *
 * agent_secret plaintext is stored only in agent_secret_once for installer polling;
 * it is never returned to the caller.
 */
function asscmo_approve_enrollment_request(PDO $pdo, int $requestId, string $pepper): void {
    $loadStmt = $pdo->prepare("
        SELECT id, uid, status, (expires_at <= CURRENT_TIMESTAMP) AS is_expired
          FROM agent_enrollment_requests
         WHERE id = :request_id
    ");
    $loadStmt->execute([':request_id' => $requestId]);
    $row = $loadStmt->fetch();

    if (!is_array($row)) {
        throw new AsscmoEnrollmentNotFoundException('Not found');
    }

    $status = $row['status'];

    if ($status === 'expired' || $row['is_expired']) {
        throw new AsscmoEnrollmentExpiredException('Gone');
    }

    if ($status !== 'pending') {
        throw new AsscmoEnrollmentNotFoundException('Not found');
    }

    $uid = $row['uid'];
    if ($uid === null || $uid === '') {
        error_log('ASS-CMO enrollment approve: request has no uid: ' . $requestId);
        throw new RuntimeException('Enrollment approve failed');
    }

    $agentSecret = asscmo_generate_agent_secret();
    $agentSecretHash = asscmo_hmac_sha256($agentSecret, $pepper);

    $pdo->beginTransaction();
    try {
        $authLoadStmt = $pdo->prepare("
            SELECT uid, status, disabled_at, revoked_at
              FROM agent_auth
             WHERE uid = :uid
             FOR UPDATE
        ");
        $authLoadStmt->execute([':uid' => $uid]);
        $existingAuth = $authLoadStmt->fetch();

        if (is_array($existingAuth)) {
            $existingStatus = (string)($existingAuth['status'] ?? '');
            $isDisabled = $existingStatus === 'disabled' || ($existingAuth['disabled_at'] ?? null) !== null;
            $isRevoked = $existingStatus === 'revoked' || ($existingAuth['revoked_at'] ?? null) !== null;

            if ($existingStatus === 'active' || $isDisabled) {
                throw new AsscmoEnrollmentAlreadyEnrolledException('UID already enrolled');
            }

            if ($isRevoked) {
                $archived = asscmo_archive_revoked_agent_auth_for_reenrollment($pdo, $uid, $requestId);
                if (!$archived) {
                    throw new RuntimeException('Enrollment approve failed: revoked agent_auth row could not be archived');
                }
            }
        }

        $insertAuthStmt = $pdo->prepare("
            INSERT INTO agent_auth (uid, agent_secret_hash, agent_secret_hash_algorithm, created_from_enrollment_request_id)
            VALUES (:uid, :agent_secret_hash, 'hmac-sha256', :request_id)
        ");
        $insertAuthStmt->execute([
            ':uid' => $uid,
            ':agent_secret_hash' => $agentSecretHash,
            ':request_id' => $requestId,
        ]);

        $approveStmt = $pdo->prepare("
            UPDATE agent_enrollment_requests
               SET status = 'approved',
                   approved_at = CURRENT_TIMESTAMP,
                   agent_secret_once = :agent_secret_once
             WHERE id = :request_id
               AND status = 'pending'
               AND expires_at > CURRENT_TIMESTAMP
        ");
        $approveStmt->execute([
            ':agent_secret_once' => $agentSecret,
            ':request_id' => $requestId,
        ]);

        if ($approveStmt->rowCount() !== 1) {
            $pdo->rollBack();
            $recheckStmt = $pdo->prepare("
                SELECT status, (expires_at <= CURRENT_TIMESTAMP) AS is_expired
                  FROM agent_enrollment_requests
                 WHERE id = :request_id
            ");
            $recheckStmt->execute([':request_id' => $requestId]);
            $recheck = $recheckStmt->fetch();
            if (is_array($recheck) && ($recheck['status'] === 'expired' || $recheck['is_expired'])) {
                throw new AsscmoEnrollmentExpiredException('Gone');
            }
            throw new AsscmoEnrollmentNotFoundException('Not found');
        }

        $pdo->commit();
    } catch (AsscmoEnrollmentNotFoundException | AsscmoEnrollmentExpiredException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (AsscmoEnrollmentAlreadyEnrolledException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23505') {
            throw new AsscmoEnrollmentAlreadyEnrolledException('UID already enrolled');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Denies a pending, non-expired enrollment request.
 *
 * Uses a single atomic UPDATE; no transaction needed.
 * Throws AsscmoEnrollmentExpiredException (→ 410) or AsscmoEnrollmentNotFoundException (→ 404)
 * when the request cannot be denied. Never creates agent_auth or touches agent_secret_once.
 */
function asscmo_deny_enrollment_request(PDO $pdo, int $requestId, ?string $deniedBy = null, ?string $reason = null): void {
    $denyStmt = $pdo->prepare("
        UPDATE agent_enrollment_requests
           SET status        = 'denied',
               denied_at     = CURRENT_TIMESTAMP,
               denied_by     = :denied_by,
               denial_reason = :denial_reason
         WHERE id         = :request_id
           AND status     = 'pending'
           AND expires_at > CURRENT_TIMESTAMP
    ");
    $denyStmt->execute([
        ':request_id'    => $requestId,
        ':denied_by'     => $deniedBy,
        ':denial_reason' => $reason,
    ]);

    if ($denyStmt->rowCount() !== 1) {
        $recheckStmt = $pdo->prepare("
            SELECT status, (expires_at <= CURRENT_TIMESTAMP) AS is_expired
              FROM agent_enrollment_requests
             WHERE id = :request_id
        ");
        $recheckStmt->execute([':request_id' => $requestId]);
        $recheck = $recheckStmt->fetch();
        if (is_array($recheck) && ($recheck['status'] === 'expired' || $recheck['is_expired'])) {
            throw new AsscmoEnrollmentExpiredException('Gone');
        }
        throw new AsscmoEnrollmentNotFoundException('Not found');
    }
}

function asscmo_revoke_agent_auth(PDO $pdo, string $uid, ?string $actor = null, ?string $reason = null): void {
    if ($uid === '') {
        throw new InvalidArgumentException('UID must not be empty.');
    }

    $stmt = $pdo->prepare("
        UPDATE agent_auth
           SET status = 'revoked',
               updated_at = CURRENT_TIMESTAMP,
               revoked_at = CURRENT_TIMESTAMP,
               revoked_by = :actor,
               revoked_reason = :reason
         WHERE uid = :uid
           AND status IN ('active', 'disabled')
           AND revoked_at IS NULL
    ");
    $stmt->execute([
        ':uid' => $uid,
        ':actor' => $actor,
        ':reason' => $reason,
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new AsscmoAgentAuthNotFoundException('Not found');
    }
}
