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
?>
