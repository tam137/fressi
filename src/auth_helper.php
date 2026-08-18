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

/**
 * Ensure that the meals table exists in PostgreSQL.
 *
 * @param PDO $pdo
 * @return bool Returns true if table exists or was created successfully, false on error.
 */
/**
 * Check if a specific column exists in a PostgreSQL table.
 */
function db_has_column($pdo, $table, $column) {
    static $columnCache = [];
    $cacheKey = "{$table}.{$column}";
    if (isset($columnCache[$cacheKey])) {
        return $columnCache[$cacheKey];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.columns 
            WHERE table_name = :table AND column_name = :column LIMIT 1
        ");
        $stmt->execute(['table' => $table, 'column' => $column]);
        $exists = (bool)$stmt->fetchColumn();
        $columnCache[$cacheKey] = $exists;
        return $exists;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ensure that the meals table exists in PostgreSQL.
 *
 * @param PDO $pdo
 * @return bool Returns true if table exists or was created successfully, false on error.
 */
function ensure_meals_table_exists($pdo) {
    try {
        // Fast path: Check if table exists
        $pdo->query("SELECT 1 FROM meals LIMIT 0");
    } catch (Exception $e) {
        // Table does not exist, attempt to create it
        try {
            $createSql = "
                CREATE TABLE IF NOT EXISTS meals (
                    id BIGSERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    consumed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    title VARCHAR(255) NOT NULL DEFAULT 'Mahlzeit',
                    image_filename VARCHAR(255) NOT NULL DEFAULT '',
                    ai_model VARCHAR(100),
                    ai_attempts INTEGER NOT NULL DEFAULT 1,
                    processing_time_ms INTEGER NOT NULL DEFAULT 0,
                    ingredients TEXT,
                    health_rating TEXT,
                    calories INTEGER NOT NULL DEFAULT 0,
                    portion INTEGER NOT NULL DEFAULT 100,
                    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
                );
            ";
            $pdo->exec($createSql);
        } catch (Exception $ex) {
            error_log("Failed to create meals table: " . $ex->getMessage());
        }
    }

    // Ensure portion column exists
    try {
        $pdo->exec("ALTER TABLE meals ADD COLUMN IF NOT EXISTS portion INTEGER DEFAULT 100;");
        $pdo->exec("UPDATE meals SET portion = 100 WHERE portion IS NULL;");
    } catch (Exception $ex) {
        error_log("Failed to alter meals table column portion: " . $ex->getMessage());
    }

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meals_account_consumed ON meals(account_id, consumed_at DESC);");
    } catch (Exception $ex) {
        // index creation error ignored
    }

    return true;
}

/**
 * Ensure that the favorites table exists in PostgreSQL.
 *
 * @param PDO $pdo
 * @return bool Returns true if table exists or was created successfully, false on error.
 */
function ensure_favorites_table_exists($pdo) {
    try {
        $pdo->query("SELECT 1 FROM favorites LIMIT 0");
    } catch (Exception $e) {
        try {
            $createSql = "
                CREATE TABLE IF NOT EXISTS favorites (
                    id BIGSERIAL PRIMARY KEY,
                    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                    meal_id BIGINT NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
                    title VARCHAR(255) NOT NULL DEFAULT 'Mahlzeit',
                    image_filename VARCHAR(255) DEFAULT '',
                    ingredients TEXT,
                    health_rating TEXT,
                    calories INTEGER NOT NULL DEFAULT 0,
                    portion INTEGER NOT NULL DEFAULT 100,
                    consumed_at TIMESTAMPTZ,
                    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    last_used_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT unique_user_meal_fav UNIQUE (account_id, meal_id)
                );
            ";
            $pdo->exec($createSql);
        } catch (Exception $ex) {
            error_log("Failed to create favorites table: " . $ex->getMessage());
        }
    }

    try {
        $pdo->exec("ALTER TABLE favorites ADD COLUMN IF NOT EXISTS last_used_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP;");
        $pdo->exec("UPDATE favorites SET last_used_at = created_at WHERE last_used_at IS NULL;");
    } catch (Exception $ex) {
        error_log("Failed to alter favorites table column (last_used_at): " . $ex->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE favorites ADD COLUMN IF NOT EXISTS portion INTEGER DEFAULT 100;");
        $pdo->exec("UPDATE favorites SET portion = 100 WHERE portion IS NULL;");
    } catch (Exception $ex) {
        error_log("Failed to alter favorites table column (portion): " . $ex->getMessage());
    }

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_favorites_user_meal ON favorites(account_id, meal_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_favorites_last_used ON favorites(account_id, last_used_at DESC);");
    } catch (Exception $ex) {
        // index creation error ignored
    }

    return true;
}
?>

