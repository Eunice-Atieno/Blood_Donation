<?php
/**
 * Auth — Authentication and Role-Based Access Control
 *
 * Manages staff login, session lifecycle, and RBAC enforcement.
 *
 * Session payload: ['staff_id' => int, 'role' => string, 'last_active' => int]
 *
 * Requirements: 11.1, 11.2, 11.3, 11.4, 11.5, 11.6
 */

require_once __DIR__ . '/../config/db.php';

class Auth
{
    /** Session inactivity timeout in seconds (30 minutes). */
    private const SESSION_TIMEOUT = 1800;

    /** Number of consecutive failed attempts before account lockout. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** Valid role values as defined in the staff ENUM. */
    public const ROLES = ['Administrator', 'Doctor', 'Lab_Technician'];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Attempt to log in a staff member.
     *
     * Fetches the staff record by username, verifies the password, and on
     * success starts a session with the correct payload.  On failure the
     * failed-attempt counter is incremented and the account is locked when
     * the counter reaches MAX_FAILED_ATTEMPTS.
     *
     * @param string $username Plain-text username.
     * @param string $password Plain-text password to verify against the stored hash.
     * @return array|false Session payload array on success, false on failure.
     */
    public static function login(string $username, string $password): array|false
    {
        $pdo  = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT id, password_hash, role, failed_attempts, locked FROM staff WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $staff = $stmt->fetch();

        if ($staff === false) {
            // Unknown username — no counter to increment.
            return false;
        }

        // Reject locked accounts immediately.
        if ((int) $staff['locked'] === 1) {
            return false;
        }

        // Verify password.
        if (!password_verify($password, $staff['password_hash'])) {
            self::incrementFailedAttempts((int) $staff['id']);
            return false;
        }

        // Successful login — reset failed_attempts counter and start session.
        $pdo->prepare('UPDATE staff SET failed_attempts = 0 WHERE id = ?')
            ->execute([$staff['id']]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $payload = [
            'staff_id'    => (int) $staff['id'],
            'role'        => $staff['role'],
            'last_active' => time(),
        ];

        $_SESSION['auth'] = $payload;

        return $payload;
    }

    /**
     * Destroy the current session, logging the staff member out.
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Enforce that the current request has a valid, non-expired session whose
     * role is in the allowed list.
     *
     * Exits with HTTP 401 JSON if there is no valid session or the session has
     * timed out.  Exits with HTTP 403 JSON if the role is not permitted.
     *
     * @param string|array $roles Allowed role(s).
     */
    public static function requireRole(string|array $roles): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Check session existence.
        if (empty($_SESSION['auth'])) {
            self::respondAndExit(401, ['error' => 'unauthenticated']);
        }

        $auth = $_SESSION['auth'];

        // Check session timeout (30-minute inactivity window).
        if ((time() - (int) $auth['last_active']) >= self::SESSION_TIMEOUT) {
            session_destroy();
            self::respondAndExit(401, ['error' => 'unauthenticated']);
        }

        // Refresh last_active timestamp.
        $_SESSION['auth']['last_active'] = time();

        // Normalise $roles to an array.
        $allowed = is_array($roles) ? $roles : [$roles];

        if (!in_array($auth['role'], $allowed, true)) {
            self::respondAndExit(403, ['error' => 'forbidden']);
        }
    }

    /**
     * Return the current session's staff data, or null if not authenticated.
     *
     * @return array|null Session payload or null.
     */
    public static function currentStaff(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION['auth'] ?? null;
    }

    /**
     * Increment the failed_attempts counter for a staff account.
     * Locks the account when the counter reaches MAX_FAILED_ATTEMPTS.
     *
     * @param int $staffId Staff record primary key.
     */
    public static function incrementFailedAttempts(int $staffId): void
    {
        $pdo = getDbConnection();

        $pdo->prepare('UPDATE staff SET failed_attempts = failed_attempts + 1 WHERE id = ?')
            ->execute([$staffId]);

        // Re-fetch the updated counter.
        $stmt = $pdo->prepare('SELECT failed_attempts FROM staff WHERE id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $row = $stmt->fetch();

        if ($row && (int) $row['failed_attempts'] >= self::MAX_FAILED_ATTEMPTS) {
            self::lockAccount($staffId);
        }
    }

    /**
     * Lock a staff account by setting locked = 1.
     *
     * @param int $staffId Staff record primary key.
     */
    public static function lockAccount(int $staffId): void
    {
        getDbConnection()
            ->prepare('UPDATE staff SET locked = 1 WHERE id = ?')
            ->execute([$staffId]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Emit a JSON response with the given HTTP status code and exit.
     *
     * @param int   $status HTTP status code.
     * @param array $body   Associative array to encode as JSON.
     */
    private static function respondAndExit(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
        exit;
    }
}
