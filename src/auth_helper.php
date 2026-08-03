<?php
// Set global timezone for Germany
date_default_timezone_set('Europe/Berlin');

// Secure session configuration
ini_set('session.cookie_httponly', 1);
$is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
             (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if ($is_secure) {
    ini_set('session.cookie_secure', 1);
}
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database configuration from project root or server path
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists('/var/www/fressi/config.php')) {
    require_once '/var/www/fressi/config.php';
} elseif (file_exists('/var/www/fressi_config.php')) {
    require_once '/var/www/fressi_config.php';
} else {
    $db_config = [
        'host' => '127.0.0.1',
        'port' => '5433',
        'dbname' => 'fressi',
        'user' => 'web_login_user',
        'password' => 'YOUR_DATABASE_PASSWORD_HERE'
    ];
}

/**
 * Establish a PDO database connection to the PostgreSQL fressi instance.
 */
function get_db_connection() {
    global $db_config;
    try {
        $dbname = $db_config['dbname'];
        $dsn = "pgsql:host=" . $db_config['host'] . ";port=" . $db_config['port'] . ";dbname=" . $dbname;
        $pdo = new PDO($dsn, $db_config['user'], $db_config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failure: " . $e->getMessage());
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax_upload']);
        if ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Systemfehler. Verbindung zur Datenbank fehlgeschlagen.']);
            exit;
        }
        die("Systemfehler. Verbindung zur Datenbank fehlgeschlagen.");
    }
}

/**
 * Verify session or check remember_me cookie for automatic login.
 */
function check_remember_me() {
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        return true;
    }

    if (isset($_COOKIE['remember_me'])) {
        $cookie_val = $_COOKIE['remember_me'];
        $parts = explode(':', $cookie_val);
        if (count($parts) !== 2) {
            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
            return false;
        }

        list($selector, $validator) = $parts;

        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("
                SELECT r.id AS token_id, r.token_hash, r.account_id, a.username, a.is_active, a.role
                FROM remember_me_tokens r
                JOIN accounts a ON r.account_id = a.id
                WHERE r.selector = :selector AND r.expires_at > CURRENT_TIMESTAMP
            ");
            $stmt->execute(['selector' => $selector]);
            $token_row = $stmt->fetch();

            if ($token_row) {
                if (hash_equals($token_row['token_hash'], hash('sha256', $validator))) {
                    if ($token_row['is_active']) {
                        $_SESSION['logged_in'] = true;
                        $_SESSION['user_id'] = $token_row['account_id'];
                        $_SESSION['username'] = $token_row['username'];
                        $_SESSION['role'] = $token_row['role'];

                        $stmt_update = $pdo->prepare("UPDATE accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
                        $stmt_update->execute(['id' => $token_row['account_id']]);

                        rotate_remember_token($pdo, $token_row['token_id'], $token_row['account_id']);
                        return true;
                    }
                }
            }

            setcookie('remember_me', '', time() - 3600, '/', '', true, true);
            if ($token_row) {
                $stmt_del = $pdo->prepare("DELETE FROM remember_me_tokens WHERE id = :id");
                $stmt_del->execute(['id' => $token_row['token_id']]);
            }
        } catch (Exception $e) {
            error_log("Error in remember me authentication: " . $e->getMessage());
        }
    }
    return false;
}

/**
 * Generate a new remember_me token and set the cookie.
 */
function set_remember_token($pdo, $account_id) {
    try {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(24));
        $token_hash = hash('sha256', $validator);

        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        $expires_dt = date('Y-m-d H:i:sP', $expires);

        $stmt = $pdo->prepare("
            INSERT INTO remember_me_tokens (account_id, selector, token_hash, expires_at)
            VALUES (:account_id, :selector, :token_hash, :expires_at)
        ");
        $stmt->execute([
            'account_id' => $account_id,
            'selector' => $selector,
            'token_hash' => $token_hash,
            'expires_at' => $expires_dt
        ]);

        setcookie('remember_me', "$selector:$validator", $expires, '/', '', true, true);
    } catch (Exception $e) {
        error_log("Failed to set remember me token: " . $e->getMessage());
    }
}

/**
 * Rotate the remember token by deleting the old one and creating a new one.
 */
function rotate_remember_token($pdo, $token_id, $account_id) {
    try {
        $stmt_del = $pdo->prepare("DELETE FROM remember_me_tokens WHERE id = :id");
        $stmt_del->execute(['id' => $token_id]);
        set_remember_token($pdo, $account_id);
    } catch (Exception $e) {
        error_log("Failed to rotate remember token: " . $e->getMessage());
    }
}
?>
