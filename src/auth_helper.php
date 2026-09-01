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
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax_upload']);
    if ($is_ajax) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Konfigurationsdatei config.php nicht gefunden. Bitte config.example.php als Vorlage nutzen.']);
        exit;
    }
    die("Konfigurationsdatei config.php nicht gefunden. Bitte kopiere config.example.php nach config.php und trage deine Zugangsdaten ein.");
}

// Role names as stored in accounts.role (constrained by accounts_role_check)
const ROLE_ADMIN = 'admin';
const ROLE_USER  = 'user';

// Fallback AI configuration, used whenever no admin settings are stored
const AI_DEFAULT_MODELS     = ['gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash'];
const AI_DEFAULT_MAX_PASSES = 3;
const AI_MAX_MODELS         = 10;
const AI_MAX_PASSES_LIMIT   = 5;

// Keys stored per account in user_settings
const USER_SETTING_GEMINI_KEY = 'gemini_api_key';

// Password policy for self-service changes on settings.php
const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 128;

/**
 * Detect an AJAX or upload request, so failures can be answered with JSON.
 */
function is_ajax_request() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || isset($_POST['ajax_upload']);
}

/**
 * Load the current account row and enforce that it is still active.
 *
 * Terminates the request (JSON for AJAX, redirect otherwise) when the account
 * is missing or deactivated. Call only after check_remember_me() succeeded.
 *
 * @param PDO $pdo
 * @return array The account row including its current role
 */
function load_current_user($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id, username, is_active, role FROM accounts WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Konto deaktiviert.']);
                exit;
            }
            header('Location: logout.php');
            exit;
        }

        return $user;
    } catch (Exception $e) {
        error_log("Security check failed: " . $e->getMessage());
        if (is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Systemfehler. Zugriff verweigert.']);
            exit;
        }
        die("Systemfehler. Zugriff verweigert.");
    }
}

/**
 * Check the admin role against the account row read from the database.
 *
 * Deliberately ignores $_SESSION['role'], which is only refreshed at login and
 * would stay stale for up to 30 days behind a remember_me cookie.
 *
 * @param array|null $user Row returned by load_current_user()
 * @return bool
 */
function is_admin($user) {
    return is_array($user) && isset($user['role']) && $user['role'] === ROLE_ADMIN;
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
 * Ensure that the meals table exists in PostgreSQL and has all required columns.
 *
 * @param PDO $pdo
 * @return bool Returns true if table and portion column exist, false on error.
 */
function ensure_meals_table_exists($pdo) {
    try {
        // Step 1: Create table if missing
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
    } catch (Exception $e) {
        error_log("Failed to create meals table: " . $e->getMessage());
    }

    // Step 2: Ensure portion column exists
    try {
        $pdo->exec("ALTER TABLE meals ADD COLUMN IF NOT EXISTS portion INTEGER NOT NULL DEFAULT 100;");
        $pdo->exec("UPDATE meals SET portion = 100 WHERE portion IS NULL;");
    } catch (Exception $e) {
        error_log("Failed to alter meals table column (portion): " . $e->getMessage());
    }

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_meals_account_consumed ON meals(account_id, consumed_at DESC);");
    } catch (Exception $ex) {
        // index creation error ignored
    }

    // Step 3: Strict check that portion column exists
    try {
        $pdo->query("SELECT portion FROM meals LIMIT 0");
        return true;
    } catch (Exception $e) {
        error_log("Strict schema check failed: column 'portion' is missing from 'meals': " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure that the favorites table exists in PostgreSQL and has all required columns.
 *
 * @param PDO $pdo
 * @return bool Returns true if table and portion column exist, false on error.
 */
function ensure_favorites_table_exists($pdo) {
    try {
        $createSql = "
            CREATE TABLE IF NOT EXISTS favorites (
                id BIGSERIAL PRIMARY KEY,
                account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                meal_id BIGINT REFERENCES meals(id) ON DELETE SET NULL,
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
    } catch (Exception $e) {
        error_log("Failed to create favorites table: " . $e->getMessage());
    }

    // Migration: ensure meal_id is nullable and foreign key uses ON DELETE SET NULL
    try {
        $pdo->exec("ALTER TABLE favorites ALTER COLUMN meal_id DROP NOT NULL;");

        $chkFk = $pdo->query("
            SELECT confdeltype 
            FROM pg_constraint 
            WHERE conname = 'favorites_meal_id_fkey' 
              AND conrelid = 'favorites'::regclass
        ")->fetchColumn();

        if ($chkFk !== 'n') {
            $pdo->exec("
                DO $$
                DECLARE
                    r RECORD;
                BEGIN
                    FOR r IN (
                        SELECT tc.constraint_name
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage kcu
                          ON tc.constraint_name = kcu.constraint_name
                          AND tc.table_schema = kcu.table_schema
                        WHERE tc.constraint_type = 'FOREIGN KEY'
                          AND tc.table_name = 'favorites'
                          AND kcu.column_name = 'meal_id'
                    ) LOOP
                        EXECUTE 'ALTER TABLE favorites DROP CONSTRAINT IF EXISTS ' || quote_ident(r.constraint_name);
                    END LOOP;

                    ALTER TABLE favorites ADD CONSTRAINT favorites_meal_id_fkey FOREIGN KEY (meal_id) REFERENCES meals(id) ON DELETE SET NULL;
                END $$;
            ");
        }
    } catch (Exception $ex) {
        error_log("Failed to update favorites foreign key constraint to ON DELETE SET NULL: " . $ex->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE favorites ADD COLUMN IF NOT EXISTS last_used_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP;");
        $pdo->exec("UPDATE favorites SET last_used_at = created_at WHERE last_used_at IS NULL;");
    } catch (Exception $ex) {
        error_log("Failed to alter favorites table column (last_used_at): " . $ex->getMessage());
    }

    try {
        $pdo->exec("ALTER TABLE favorites ADD COLUMN IF NOT EXISTS portion INTEGER NOT NULL DEFAULT 100;");
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

    // Step 3: Strict check that portion column exists
    try {
        $pdo->query("SELECT portion FROM favorites LIMIT 0");
        return true;
    } catch (Exception $e) {
        error_log("Strict schema check failed: column 'portion' is missing from 'favorites': " . $e->getMessage());
        return false;
    }
}

/**
 * Ensure that the app_settings key/value table exists in PostgreSQL.
 *
 * @param PDO $pdo
 * @return bool Returns true if the table exists or was created successfully.
 */
function ensure_app_settings_table_exists($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key   VARCHAR(64) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by    BIGINT REFERENCES accounts(id) ON DELETE SET NULL
            );
        ");
        return true;
    } catch (Exception $e) {
        error_log("Failed to create app_settings table: " . $e->getMessage());
        return false;
    }
}

/**
 * Normalise a list of Gemini model names: trim, validate, deduplicate, cap length.
 *
 * The name is interpolated into the Gemini API URL, so only safe characters pass.
 *
 * @param mixed $models
 * @return string[] Validated model names in their original order
 */
function sanitize_ai_models($models) {
    if (!is_array($models)) {
        return [];
    }

    $clean = [];
    foreach ($models as $model) {
        if (!is_string($model)) {
            continue;
        }
        $model = trim($model);
        if ($model === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $model)) {
            continue;
        }
        if (in_array($model, $clean, true)) {
            continue;
        }
        $clean[] = $model;
        if (count($clean) >= AI_MAX_MODELS) {
            break;
        }
    }
    return $clean;
}

/**
 * Read the AI model configuration chosen by an admin.
 *
 * Falls back to the built-in defaults whenever nothing valid is stored, so a
 * settings problem can never break the AI analysis. The result is cached per
 * request; pass $forceReload after a write.
 *
 * @param PDO|null $pdo
 * @param bool $forceReload
 * @return array ['models' => string[], 'max_passes' => int, 'updated_at' => ?string,
 *                'updated_by' => ?string, 'is_default' => bool]
 */
function get_ai_settings($pdo, $forceReload = false) {
    static $cached = null;

    if ($cached !== null && !$forceReload) {
        return $cached;
    }

    $settings = [
        'models'     => AI_DEFAULT_MODELS,
        'max_passes' => AI_DEFAULT_MAX_PASSES,
        'updated_at' => null,
        'updated_by' => null,
        'is_default' => true
    ];

    // Without a connection, stay on the defaults but do not cache them:
    // a later call in the same request may well have one.
    if (!($pdo instanceof PDO)) {
        return $settings;
    }

    try {
        $stmt = $pdo->query("
            SELECT s.setting_key, s.setting_value, s.updated_at, a.username
            FROM app_settings s
            LEFT JOIN accounts a ON s.updated_by = a.id
            WHERE s.setting_key IN ('ai_models', 'ai_max_passes')
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table missing or unreadable — the defaults keep the analysis working.
        error_log("Failed to read AI settings, using defaults: " . $e->getMessage());
        return $settings;
    }

    foreach ($rows as $row) {
        if ($row['setting_key'] === 'ai_models') {
            $decoded = json_decode($row['setting_value'], true);
            $models = sanitize_ai_models($decoded);
            if (!empty($models)) {
                $settings['models'] = $models;
                $settings['is_default'] = false;
            }
        } elseif ($row['setting_key'] === 'ai_max_passes') {
            $passes = filter_var($row['setting_value'], FILTER_VALIDATE_INT);
            if ($passes !== false && $passes >= 1 && $passes <= AI_MAX_PASSES_LIMIT) {
                $settings['max_passes'] = $passes;
                $settings['is_default'] = false;
            }
        }

        if (!empty($row['updated_at']) && ($settings['updated_at'] === null || $row['updated_at'] > $settings['updated_at'])) {
            $settings['updated_at'] = $row['updated_at'];
            $settings['updated_by'] = $row['username'];
        }
    }

    $cached = $settings;
    return $settings;
}

/**
 * Validate and persist the AI model configuration. Admin-only caller.
 *
 * @param PDO $pdo
 * @param mixed $models Ordered list of model names
 * @param mixed $maxPasses Number of passes over the model list
 * @param int $accountId Account performing the change
 * @return array ['success' => bool, 'error' => string|null]
 */
function save_ai_settings($pdo, $models, $maxPasses, $accountId) {
    $models = sanitize_ai_models($models);
    if (empty($models)) {
        return ['success' => false, 'error' => 'Bitte gib mindestens ein gültiges Modell an.'];
    }

    $passes = filter_var($maxPasses, FILTER_VALIDATE_INT);
    if ($passes === false || $passes < 1 || $passes > AI_MAX_PASSES_LIMIT) {
        return ['success' => false, 'error' => 'Die Anzahl der Durchläufe muss zwischen 1 und ' . AI_MAX_PASSES_LIMIT . ' liegen.'];
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value, updated_at, updated_by)
            VALUES (:key, :value, CURRENT_TIMESTAMP, :account_id)
            ON CONFLICT (setting_key) DO UPDATE
            SET setting_value = EXCLUDED.setting_value,
                updated_at    = EXCLUDED.updated_at,
                updated_by    = EXCLUDED.updated_by
        ");

        $pdo->beginTransaction();
        $stmt->execute([
            'key' => 'ai_models',
            'value' => json_encode(array_values($models)),
            'account_id' => $accountId
        ]);
        $stmt->execute([
            'key' => 'ai_max_passes',
            'value' => (string)$passes,
            'account_id' => $accountId
        ]);
        $pdo->commit();

        get_ai_settings($pdo, true);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to save AI settings: " . $e->getMessage());
        return ['success' => false, 'error' => 'Die Einstellungen konnten nicht gespeichert werden.'];
    }
}

/**
 * Drop the stored AI configuration so the built-in defaults apply again.
 *
 * @param PDO $pdo
 * @return array ['success' => bool, 'error' => string|null]
 */
function reset_ai_settings($pdo) {
    try {
        $pdo->exec("DELETE FROM app_settings WHERE setting_key IN ('ai_models', 'ai_max_passes')");
        get_ai_settings($pdo, true);
        return ['success' => true, 'error' => null];
    } catch (Exception $e) {
        error_log("Failed to reset AI settings: " . $e->getMessage());
        return ['success' => false, 'error' => 'Die Einstellungen konnten nicht zurückgesetzt werden.'];
    }
}

/**
 * Ensure that the per-account user_settings key/value table exists.
 *
 * Separate from app_settings on purpose: those are global and admin-owned, these
 * belong to a single account and disappear with it.
 *
 * @param PDO $pdo
 * @return bool Returns true if the table exists or was created successfully.
 */
function ensure_user_settings_table_exists($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_settings (
                account_id    INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                setting_key   VARCHAR(64) NOT NULL,
                setting_value TEXT NOT NULL,
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, setting_key)
            );
        ");
        return true;
    } catch (Exception $e) {
        error_log("Failed to create user_settings table: " . $e->getMessage());
        return false;
    }
}

/**
 * Secret used to derive the encryption key for values in user_settings.
 *
 * Prefers $app_secret from config.php and falls back to the database password, so a
 * config file that has not been updated yet keeps working. Either way the material
 * lives outside the database, so a database dump alone cannot decrypt anything.
 *
 * @return string|null
 */
function app_secret() {
    global $app_secret, $db_config;

    if (isset($app_secret) && is_string($app_secret)) {
        $candidate = trim($app_secret);
        if ($candidate !== '' && $candidate !== 'YOUR_RANDOM_APP_SECRET_HERE') {
            return $candidate;
        }
    }

    if (isset($db_config['password']) && is_string($db_config['password']) && $db_config['password'] !== '') {
        return $db_config['password'];
    }

    return null;
}

/**
 * Derive the AES key for user secrets from app_secret().
 *
 * @return string|null 32 raw bytes, or null when no secret is configured
 */
function user_secret_cipher_key() {
    $secret = app_secret();
    if ($secret === null || !function_exists('hash_hkdf')) {
        return null;
    }
    return hash_hkdf('sha256', $secret, 32, 'fressi:user_settings:v1');
}

/**
 * Encrypt a user secret for storage (AES-256-GCM).
 *
 * @param string $plain
 * @return string|null "v1:" . base64(iv|tag|ciphertext), or null on any failure
 */
function encrypt_user_secret($plain) {
    $key = user_secret_cipher_key();
    if ($key === null || !function_exists('openssl_encrypt')) {
        error_log("Cannot encrypt user secret: no app secret or OpenSSL available.");
        return null;
    }

    try {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) {
            return null;
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    } catch (Exception $e) {
        error_log("Failed to encrypt user secret: " . $e->getMessage());
        return null;
    }
}

/**
 * Decrypt a value written by encrypt_user_secret().
 *
 * Returns null for anything unreadable (rotated secret, corrupted row), which callers
 * treat as "no personal value stored" so the application keeps working.
 *
 * @param string|null $stored
 * @return string|null
 */
function decrypt_user_secret($stored) {
    if (!is_string($stored) || strncmp($stored, 'v1:', 3) !== 0) {
        return null;
    }

    $key = user_secret_cipher_key();
    if ($key === null || !function_exists('openssl_decrypt')) {
        return null;
    }

    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) <= 28) {
        return null;
    }

    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return ($plain === false) ? null : $plain;
}

/**
 * Read one raw (still encrypted) setting value for an account.
 *
 * @param PDO $pdo
 * @param int $accountId
 * @param string $key
 * @return string|null
 */
function get_user_setting($pdo, $accountId, $key) {
    if (!($pdo instanceof PDO) || empty($accountId)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT setting_value FROM user_settings
            WHERE account_id = :account_id AND setting_key = :key
        ");
        $stmt->execute(['account_id' => $accountId, 'key' => $key]);
        $value = $stmt->fetchColumn();
        return ($value === false) ? null : $value;
    } catch (Exception $e) {
        error_log("Failed to read user setting '" . $key . "': " . $e->getMessage());
        return null;
    }
}

/**
 * Store one raw setting value for an account.
 *
 * @param PDO $pdo
 * @param int $accountId
 * @param string $key
 * @param string $value Already encrypted when it holds a secret
 * @return bool
 */
function set_user_setting($pdo, $accountId, $key, $value) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (account_id, setting_key, setting_value, updated_at)
            VALUES (:account_id, :key, :value, CURRENT_TIMESTAMP)
            ON CONFLICT (account_id, setting_key) DO UPDATE
            SET setting_value = EXCLUDED.setting_value,
                updated_at    = EXCLUDED.updated_at
        ");
        $stmt->execute(['account_id' => $accountId, 'key' => $key, 'value' => $value]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to write user setting '" . $key . "': " . $e->getMessage());
        return false;
    }
}

/**
 * Remove one setting for an account.
 *
 * @param PDO $pdo
 * @param int $accountId
 * @param string $key
 * @return int|false Number of rows removed, or false when the statement failed
 */
function delete_user_setting($pdo, $accountId, $key) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM user_settings WHERE account_id = :account_id AND setting_key = :key
        ");
        $stmt->execute(['account_id' => $accountId, 'key' => $key]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log("Failed to delete user setting '" . $key . "': " . $e->getMessage());
        return false;
    }
}

/**
 * Validate a Gemini API key entered by a user.
 *
 * Deliberately loose — Google's key format is not contractual, so only obviously
 * wrong input is rejected.
 *
 * @param mixed $key
 * @return string|null The trimmed key, or null when it is not usable
 */
function sanitize_gemini_key($key) {
    if (!is_string($key)) {
        return null;
    }
    $key = trim($key);
    if ($key === '' || !preg_match('/^[A-Za-z0-9._-]{20,200}$/', $key)) {
        return null;
    }
    return $key;
}

/**
 * Read the decrypted personal Gemini key of an account.
 *
 * Returns null whenever none is stored or the value cannot be decrypted, so the
 * caller falls back to the default key from config.php. Cached per request.
 *
 * @param PDO|null $pdo
 * @param int|null $accountId
 * @param bool $forceReload Pass true after writing or deleting the key
 * @return string|null
 */
function get_user_gemini_key($pdo, $accountId, $forceReload = false) {
    static $cache = [];

    if ($forceReload) {
        unset($cache[$accountId]);
    }

    if (!($pdo instanceof PDO) || empty($accountId)) {
        return null;
    }

    if (array_key_exists($accountId, $cache)) {
        return $cache[$accountId];
    }

    $stored = get_user_setting($pdo, $accountId, USER_SETTING_GEMINI_KEY);
    $plain  = ($stored === null) ? null : decrypt_user_secret($stored);

    if ($stored !== null && $plain === null) {
        error_log("Stored Gemini key of account " . (int)$accountId . " could not be decrypted; using the default key.");
    }

    $cache[$accountId] = $plain;
    return $plain;
}

/**
 * Verify a password against the stored hash of an account.
 *
 * @param PDO $pdo
 * @param int $accountId
 * @param string $password
 * @return bool
 */
function verify_user_password($pdo, $accountId, $password) {
    if (!is_string($password) || $password === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM accounts WHERE id = :id");
        $stmt->execute(['id' => $accountId]);
        $hash = $stmt->fetchColumn();
        if ($hash === false || $hash === null) {
            return false;
        }
        return password_verify($password, $hash);
    } catch (Exception $e) {
        error_log("Failed to verify password: " . $e->getMessage());
        return false;
    }
}

/**
 * Check a new password against the policy: at least PASSWORD_MIN_LENGTH characters
 * with an uppercase letter, a lowercase letter and a digit, entered twice.
 *
 * @param mixed $password
 * @param mixed $repeat
 * @return array ['success' => bool, 'error' => string|null]
 */
function validate_new_password($password, $repeat) {
    if (!is_string($password) || $password === '') {
        return ['success' => false, 'error' => 'Bitte gib ein neues Passwort ein.'];
    }
    if (!is_string($repeat) || $password !== $repeat) {
        return ['success' => false, 'error' => 'Die beiden Passwörter stimmen nicht überein.'];
    }
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return ['success' => false, 'error' => 'Dein Passwort braucht mindestens ' . PASSWORD_MIN_LENGTH . ' Zeichen.'];
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        return ['success' => false, 'error' => 'Dein Passwort darf höchstens ' . PASSWORD_MAX_LENGTH . ' Zeichen haben.'];
    }
    if (!preg_match('/[a-z]/', $password)) {
        return ['success' => false, 'error' => 'Dein Passwort braucht mindestens einen Kleinbuchstaben.'];
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return ['success' => false, 'error' => 'Dein Passwort braucht mindestens einen Großbuchstaben.'];
    }
    if (!preg_match('/[0-9]/', $password)) {
        return ['success' => false, 'error' => 'Dein Passwort braucht mindestens eine Zahl.'];
    }

    return ['success' => true, 'error' => null];
}

/**
 * Hash and store a new password, then end every other session of that account.
 *
 * All remember_me tokens are dropped; the current browser gets a fresh one when it
 * had one, so only the other devices are logged out.
 *
 * @param PDO $pdo
 * @param int $accountId
 * @param string $password Already validated by validate_new_password()
 * @return array ['success' => bool, 'error' => string|null]
 */
function change_user_password($pdo, $accountId, $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        error_log("password_hash() failed for account " . (int)$accountId);
        return ['success' => false, 'error' => 'Das Passwort konnte nicht gespeichert werden.'];
    }

    try {
        $stmt = $pdo->prepare("UPDATE accounts SET password_hash = :hash WHERE id = :id");
        $stmt->execute(['hash' => $hash, 'id' => $accountId]);
    } catch (Exception $e) {
        error_log("Failed to update password: " . $e->getMessage());
        return ['success' => false, 'error' => 'Das Passwort konnte nicht gespeichert werden.'];
    }

    // Invalidate every remember_me token, then re-issue one for this browser only
    $had_cookie = isset($_COOKIE['remember_me']);
    try {
        $stmt_del = $pdo->prepare("DELETE FROM remember_me_tokens WHERE account_id = :id");
        $stmt_del->execute(['id' => $accountId]);
    } catch (Exception $e) {
        error_log("Failed to clear remember me tokens after password change: " . $e->getMessage());
    }

    if ($had_cookie) {
        set_remember_token($pdo, $accountId);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    return ['success' => true, 'error' => null];
}
/**
 * Brute-force protection for the login form.
 *
 * Failed attempts are counted per username and per client IP inside a sliding
 * window. Once a limit is reached the login is rejected immediately. This is
 * deliberately counter based rather than a sleep(): Apache runs mpm_prefork
 * with a worker pool shared by every vhost on this host, so delaying a request
 * would let an attacker exhaust that pool with a handful of parallel requests,
 * and it would not slow a parallelised attack down anyway.
 */
define('LOGIN_ATTEMPT_WINDOW_MINUTES', 15);
define('LOGIN_MAX_ATTEMPTS_PER_USER', 8);
define('LOGIN_MAX_ATTEMPTS_PER_IP', 25);

/**
 * Client IP of the current request.
 *
 * Only REMOTE_ADDR is used. X-Forwarded-For is attacker controlled and there is
 * no trusted proxy in front of Apache here, so honouring it would let anyone
 * reset their own attempt counter at will.
 */
function client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Seconds the caller has to wait before the next login attempt is accepted.
 * Returns 0 when the request is not throttled.
 */
function login_throttle_retry_after($pdo, $username, $ip) {
    // Column names come from this literal list, never from request data.
    $checks = [
        ['column' => 'username', 'value' => $username, 'limit' => LOGIN_MAX_ATTEMPTS_PER_USER],
        ['column' => 'ip_address', 'value' => $ip, 'limit' => LOGIN_MAX_ATTEMPTS_PER_IP],
    ];

    $retry_after = 0;
    foreach ($checks as $check) {
        $limit = (int) $check['limit'];
        $window = (int) LOGIN_ATTEMPT_WINDOW_MINUTES;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt, MIN(attempted_at) AS oldest
            FROM (
                SELECT attempted_at
                FROM login_attempts
                WHERE {$check['column']} = :value
                  AND attempted_at > CURRENT_TIMESTAMP - INTERVAL '{$window} minutes'
                ORDER BY attempted_at DESC
                LIMIT {$limit}
            ) recent
        ");
        $stmt->execute(['value' => $check['value']]);
        $row = $stmt->fetch();

        if ($row && (int) $row['cnt'] >= $limit && !empty($row['oldest'])) {
            // Blocked until the oldest attempt still inside the window ages out.
            $unlock_at = strtotime($row['oldest']) + $window * 60;
            $retry_after = max($retry_after, $unlock_at - time());
        }
    }

    return max(0, $retry_after);
}

/**
 * Record a failed login attempt.
 */
function record_failed_login($pdo, $username, $ip) {
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip)");
        $stmt->execute(['username' => $username, 'ip' => $ip]);

        // Opportunistic cleanup so the table cannot grow without bound.
        if (random_int(1, 100) === 1) {
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < CURRENT_TIMESTAMP - INTERVAL '1 day'");
        }
    } catch (Exception $e) {
        error_log("Failed to record login attempt: " . $e->getMessage());
    }
}

/**
 * Drop the recorded failures for a username after a successful login.
 * The per-IP counter is kept on purpose, so a valid account cannot be used to
 * reset the counter that limits password spraying from one host.
 */
function clear_failed_logins($pdo, $username) {
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = :username");
        $stmt->execute(['username' => $username]);
    } catch (Exception $e) {
        error_log("Failed to clear login attempts: " . $e->getMessage());
    }
}

?>
