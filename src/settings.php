<?php
// FRESSI — User settings: password change and personal Gemini API key
require_once 'auth_helper.php';

// Authentifizierung erzwingen (Sitzung oder Remember-Me Cookie)
if (!check_remember_me()) {
    header('Location: login.php');
    exit;
}

$pdo  = get_db_connection();
$user = load_current_user($pdo);

ensure_user_settings_table_exists($pdo);

$pw_error    = '';
$pw_success  = '';
$key_error   = '';
$key_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    if ($form === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $repeat  = $_POST['new_password_repeat'] ?? '';

        if (!verify_user_password($pdo, $user['id'], $current)) {
            $pw_error = 'Dein aktuelles Passwort stimmt nicht.';
        } else {
            $check = validate_new_password($new, $repeat);
            if (!$check['success']) {
                $pw_error = $check['error'];
            } else {
                $result = change_user_password($pdo, $user['id'], $new);
                if ($result['success']) {
                    $pw_success = 'Passwort geändert. Andere Geräte wurden abgemeldet.';
                } else {
                    $pw_error = $result['error'];
                }
            }
        }
    } elseif ($form === 'gemini_key') {
        if (isset($_POST['delete_key'])) {
            $removed = delete_user_setting($pdo, $user['id'], USER_SETTING_GEMINI_KEY);
            if ($removed === false) {
                $key_error = 'Der Schlüssel konnte nicht gelöscht werden.';
            } elseif ($removed > 0) {
                $key_success = 'Dein Schlüssel wurde gelöscht. Es gilt wieder der Standard-Schlüssel der App.';
            } else {
                $key_success = 'Es war kein eigener Schlüssel hinterlegt.';
            }
        } else {
            $clean = sanitize_gemini_key($_POST['gemini_key'] ?? '');
            if ($clean === null) {
                $key_error = 'Das sieht nicht nach einem gültigen API-Schlüssel aus.';
            } else {
                $encrypted = encrypt_user_secret($clean);
                if ($encrypted === null) {
                    $key_error = 'Der Schlüssel konnte nicht verschlüsselt werden. Bitte melde dich beim Admin.';
                } elseif (set_user_setting($pdo, $user['id'], USER_SETTING_GEMINI_KEY, $encrypted)) {
                    $key_success = 'Dein Schlüssel wurde gespeichert und wird ab sofort verwendet.';
                } else {
                    $key_error = 'Der Schlüssel konnte nicht gespeichert werden.';
                }
            }
        }
    }
}

// Status des hinterlegten Schlüssels — der Wert selbst wird nie wieder ausgegeben
get_user_gemini_key($pdo, $user['id'], true);
$stored_key    = get_user_setting($pdo, $user['id'], USER_SETTING_GEMINI_KEY);
$has_key       = ($stored_key !== null);
$key_plain     = $has_key ? decrypt_user_secret($stored_key) : null;
$key_unreadable = ($has_key && $key_plain === null);
$key_suffix    = ($key_plain !== null) ? substr($key_plain, -4) : '';
unset($key_plain);
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen — fressi</title>
    <!-- Favicon & Icons -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <script>
        // Apply the stored theme before first paint to avoid a flash
        document.documentElement.setAttribute('data-theme', localStorage.getItem('fressi_theme') || 'light');
    </script>

    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
    <style>
        html, body {
            height: 100%;
            overflow: auto;
        }

        .settings-container {
            max-width: 640px;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem;
            box-sizing: border-box;
        }

        .settings-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .settings-title {
            font-family: var(--font-heading);
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--text-primary);
        }

        .settings-back {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.55rem 1rem;
            border-radius: var(--radius-full);
            border: 2px solid var(--border-vintage);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .settings-card {
            background: var(--bg-card);
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-paper);
            padding: 1.5rem 1.25rem;
            margin-bottom: 1.25rem;
            box-sizing: border-box;
        }

        .settings-card h2 {
            font-family: var(--font-heading);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.35rem;
        }

        .settings-hint {
            color: var(--text-secondary);
            font-size: 0.88rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .alert-banner {
            padding: 0.85rem 1.2rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border: 2px solid var(--border-vintage);
            background: var(--bg-secondary);
        }

        .alert-danger {
            border-color: var(--accent-terracotta);
            color: var(--accent-terracotta);
        }

        .alert-success {
            border-color: var(--accent-green);
            color: var(--accent-green);
        }

        .form-label {
            display: block;
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            color: var(--text-primary);
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg-secondary);
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: var(--font-body);
            font-size: 0.95rem;
            outline: none;
            box-sizing: border-box;
            transition: border-color var(--transition-fast);
        }

        .form-input:focus {
            border-color: var(--accent-green);
            background: var(--bg-card);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .input-with-toggle {
            display: flex;
            gap: 0.5rem;
        }

        .toggle-btn {
            width: 48px;
            flex-shrink: 0;
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-size: 1.05rem;
            line-height: 1;
        }

        .rule-list {
            list-style: none;
            margin: 0 0 1rem;
            padding: 0.7rem 0.9rem;
            border: 2px dashed var(--border-dashed);
            border-radius: var(--radius-sm);
        }

        .rule-list li {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.85rem;
            line-height: 1.7;
        }

        .rule-list li.is-met {
            color: var(--accent-green);
        }

        .rule-mark {
            width: 1.1rem;
            flex-shrink: 0;
            text-align: center;
        }

        .key-status {
            padding: 0.7rem 0.9rem;
            border-radius: var(--radius-sm);
            border: 2px dashed var(--border-dashed);
            color: var(--text-secondary);
            font-size: 0.88rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .key-status.is-active {
            border-color: var(--accent-green);
            color: var(--accent-green);
        }

        .key-status.is-warning {
            border-color: var(--accent-mustard);
            color: var(--accent-mustard);
        }

        .key-status code {
            font-family: var(--font-mono);
        }

        .key-delete-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.6rem 0.85rem;
            margin-bottom: 1.25rem;
        }

        .btn-delete {
            padding: 0.7rem 1.2rem;
            border-radius: var(--radius-full);
            background: var(--bg-card);
            color: var(--accent-terracotta);
            border: 2px solid var(--accent-terracotta);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.95rem;
            white-space: nowrap;
            transition: all var(--transition-fast);
        }

        .btn-delete:hover {
            background: var(--accent-terracotta);
            color: #ffffff;
        }

        .key-delete-hint {
            color: var(--text-muted);
            font-size: 0.82rem;
            line-height: 1.4;
        }

        .settings-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .btn-primary {
            flex: 1;
            min-width: 12rem;
            padding: 0.85rem 1.5rem;
            border-radius: var(--radius-full);
            background: var(--accent-green);
            color: #ffffff;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.05rem;
            border: 2px solid var(--accent-green-hover);
            box-shadow: var(--shadow-paper);
            transition: all var(--transition-fast);
        }

        .btn-primary:hover {
            background: var(--accent-green-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-paper-hover);
        }

        .btn-secondary {
            padding: 0.85rem 1.25rem;
            border-radius: var(--radius-full);
            background: var(--bg-card);
            color: var(--accent-terracotta);
            border: 2px solid var(--border-vintage);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.95rem;
        }

        @media (max-width: 480px) {
            .settings-title { font-size: 1.6rem; }
            .btn-primary { min-width: 100%; }
            .btn-secondary { width: 100%; }
            .btn-delete { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="settings-container">
        <div class="settings-header">
            <h1 class="settings-title">👤 Einstellungen</h1>
            <a href="index.php" class="settings-back">← Zurück</a>
        </div>

        <form action="settings.php#passwort" method="POST" id="password-form">
            <input type="hidden" name="form" value="password">
            <div class="settings-card" id="passwort">
                <h2>Passwort ändern</h2>
                <p class="settings-hint">
                    Wähle ein neues Passwort. Deine anderen Geräte werden danach abgemeldet.
                </p>

                <?php if (!empty($pw_error)): ?>
                    <div class="alert-banner alert-danger">
                        <span>⚠️ <?php echo htmlspecialchars($pw_error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($pw_success)): ?>
                    <div class="alert-banner alert-success">
                        <span>✓ <?php echo htmlspecialchars($pw_success); ?></span>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="current_password" class="form-label">Aktuelles Passwort</label>
                    <input type="password" id="current_password" name="current_password" class="form-input"
                           placeholder="••••••••" autocomplete="current-password" required>
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label">Neues Passwort</label>
                    <input type="password" id="new_password" name="new_password" class="form-input"
                           placeholder="••••••••" autocomplete="new-password"
                           minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                           maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" required>
                </div>

                <div class="form-group">
                    <label for="new_password_repeat" class="form-label">Neues Passwort wiederholen</label>
                    <input type="password" id="new_password_repeat" name="new_password_repeat" class="form-input"
                           placeholder="••••••••" autocomplete="new-password"
                           minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                           maxlength="<?php echo PASSWORD_MAX_LENGTH; ?>" required>
                </div>

                <ul class="rule-list" id="password-rules">
                    <li data-rule="length"><span class="rule-mark">○</span><span>Mindestens <?php echo PASSWORD_MIN_LENGTH; ?> Zeichen</span></li>
                    <li data-rule="lower"><span class="rule-mark">○</span><span>Ein Kleinbuchstabe</span></li>
                    <li data-rule="upper"><span class="rule-mark">○</span><span>Ein Großbuchstabe</span></li>
                    <li data-rule="digit"><span class="rule-mark">○</span><span>Eine Zahl</span></li>
                    <li data-rule="match"><span class="rule-mark">○</span><span>Beide Eingaben stimmen überein</span></li>
                </ul>

                <div class="settings-actions">
                    <button type="submit" class="btn-primary">Passwort speichern</button>
                </div>
            </div>
        </form>

        <form action="settings.php#gemini" method="POST" id="gemini-form">
            <input type="hidden" name="form" value="gemini_key">
            <div class="settings-card" id="gemini">
                <h2>Eigener Gemini-Schlüssel</h2>
                <p class="settings-hint">
                    Optional. Hinterlegst du hier einen eigenen API-Schlüssel, laufen deine Analysen
                    darüber — sonst über den Standard-Schlüssel der App. Dein Schlüssel wird
                    verschlüsselt gespeichert und nie wieder angezeigt.
                </p>

                <?php if (!empty($key_error)): ?>
                    <div class="alert-banner alert-danger">
                        <span>⚠️ <?php echo htmlspecialchars($key_error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($key_success)): ?>
                    <div class="alert-banner alert-success">
                        <span>✓ <?php echo htmlspecialchars($key_success); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($key_unreadable): ?>
                    <div class="key-status is-warning">
                        Dein Schlüssel ist hinterlegt, kann aber nicht mehr gelesen werden.
                        Bitte gib ihn erneut ein — bis dahin gilt der Standard-Schlüssel.
                    </div>
                <?php elseif ($has_key): ?>
                    <div class="key-status is-active">
                        Eigener Schlüssel aktiv (endet auf <code>…<?php echo htmlspecialchars($key_suffix); ?></code>).
                    </div>
                <?php else: ?>
                    <div class="key-status">
                        Kein eigener Schlüssel hinterlegt — es wird der Standard-Schlüssel der App verwendet.
                    </div>
                <?php endif; ?>

                <?php if ($has_key): ?>
                    <div class="key-delete-row">
                        <button type="submit" name="delete_key" value="1" class="btn-delete"
                                id="key-delete" formnovalidate>
                            🗑 Schlüssel löschen
                        </button>
                        <span class="key-delete-hint">Danach laufen deine Analysen wieder über den Standard-Schlüssel.</span>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="gemini_key" class="form-label">
                        <?php echo $has_key ? 'Neuer Schlüssel' : 'API-Schlüssel'; ?>
                    </label>
                    <div class="input-with-toggle">
                        <input type="password" id="gemini_key" name="gemini_key" class="form-input"
                               placeholder="AIza…" autocomplete="off" autocapitalize="off"
                               autocorrect="off" spellcheck="false" maxlength="200">
                        <button type="button" class="toggle-btn" id="key-toggle"
                                title="Schlüssel anzeigen" aria-label="Schlüssel anzeigen">👁</button>
                    </div>
                </div>

                <div class="settings-actions">
                    <button type="submit" class="btn-primary">
                        <?php echo $has_key ? 'Schlüssel ersetzen' : 'Schlüssel speichern'; ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var MIN_LENGTH = <?php echo PASSWORD_MIN_LENGTH; ?>;

        var pw = document.getElementById('new_password');
        var repeat = document.getElementById('new_password_repeat');
        var rules = document.getElementById('password-rules');

        function checkRules() {
            var value = pw.value;
            var state = {
                length: value.length >= MIN_LENGTH,
                lower: /[a-z]/.test(value),
                upper: /[A-Z]/.test(value),
                digit: /[0-9]/.test(value),
                match: value !== '' && value === repeat.value
            };

            Array.prototype.forEach.call(rules.children, function (item) {
                var met = state[item.getAttribute('data-rule')];
                item.classList.toggle('is-met', met);
                item.querySelector('.rule-mark').textContent = met ? '✓' : '○';
            });
        }

        pw.addEventListener('input', checkRules);
        repeat.addEventListener('input', checkRules);
        checkRules();

        // Deleting the key cannot be undone, so ask first
        var deleteBtn = document.getElementById('key-delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function (event) {
                if (!confirm('Deinen eigenen Gemini-Schlüssel wirklich löschen? Danach wird wieder der Standard-Schlüssel verwendet.')) {
                    event.preventDefault();
                }
            });
        }

        // Show/hide the API key while typing it
        var keyInput = document.getElementById('gemini_key');
        var keyToggle = document.getElementById('key-toggle');

        keyToggle.addEventListener('click', function () {
            var hidden = keyInput.type === 'password';
            keyInput.type = hidden ? 'text' : 'password';
            keyToggle.textContent = hidden ? '🙈' : '👁';
            keyToggle.title = hidden ? 'Schlüssel verbergen' : 'Schlüssel anzeigen';
            keyToggle.setAttribute('aria-label', keyToggle.title);
            keyInput.focus();
        });
    })();
    </script>

</body>
</html>
