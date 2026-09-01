<?php
// FRESSI — Admin page: AI model order and retry configuration
require_once 'auth_helper.php';

// Authentifizierung erzwingen (Sitzung oder Remember-Me Cookie)
if (!check_remember_me()) {
    header('Location: login.php');
    exit;
}

$pdo  = get_db_connection();
$user = load_current_user($pdo);

// Authorisierung: die Rolle stammt aus der Datenbank, nicht aus der Session
if (!is_admin($user)) {
    header('Location: index.php');
    exit;
}

ensure_app_settings_table_exists($pdo);

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_defaults'])) {
        $result = reset_ai_settings($pdo);
        if ($result['success']) {
            $success_message = 'Standardwerte wiederhergestellt.';
        } else {
            $error_message = $result['error'];
        }
    } else {
        // One model per line, so the page also works without JavaScript
        $submitted_models = preg_split('/\r\n|\r|\n/', (string)($_POST['models'] ?? ''));
        $result = save_ai_settings($pdo, $submitted_models, $_POST['max_passes'] ?? '', $user['id']);
        if ($result['success']) {
            $success_message = 'Einstellungen gespeichert.';
        } else {
            $error_message = $result['error'];
        }
    }
}

$settings = get_ai_settings($pdo, true);
$models_text = implode("\n", $settings['models']);
$total_attempts = count($settings['models']) * $settings['max_passes'];
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — fressi</title>
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

        .admin-container {
            max-width: 640px;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem;
            box-sizing: border-box;
        }

        .admin-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .admin-title {
            font-family: var(--font-heading);
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--text-primary);
        }

        .admin-back {
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

        .admin-card {
            background: var(--bg-card);
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-paper);
            padding: 1.5rem 1.25rem;
            margin-bottom: 1.25rem;
            box-sizing: border-box;
        }

        .admin-card h2 {
            font-family: var(--font-heading);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.35rem;
        }

        .admin-hint {
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

        .model-list {
            list-style: none;
            margin: 0 0 0.85rem;
            padding: 0;
        }

        .model-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 0.75rem;
            margin-bottom: 0.5rem;
            background: var(--bg-secondary);
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-sm);
        }

        .model-rank {
            font-family: var(--font-heading);
            font-weight: 700;
            color: var(--text-muted);
            min-width: 1.4rem;
        }

        .model-name {
            flex: 1;
            font-family: var(--font-mono);
            font-size: 0.85rem;
            color: var(--text-primary);
            overflow-wrap: anywhere;
        }

        .model-btn {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-sm);
            background: var(--bg-card);
            color: var(--text-secondary);
            font-size: 1rem;
            line-height: 1;
            transition: all var(--transition-fast);
        }

        .model-btn:disabled {
            opacity: 0.35;
        }

        .model-btn-remove {
            color: var(--accent-terracotta);
        }

        .model-add {
            display: flex;
            gap: 0.5rem;
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

        textarea.form-input {
            font-family: var(--font-mono);
            min-height: 7rem;
            resize: vertical;
        }

        .form-label {
            display: block;
            font-family: var(--font-heading);
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            color: var(--text-primary);
        }

        .input-narrow {
            max-width: 8rem;
        }

        .attempts-note {
            margin-top: 0.75rem;
            padding: 0.7rem 0.9rem;
            border-radius: var(--radius-sm);
            border: 2px dashed var(--border-dashed);
            color: var(--text-secondary);
            font-size: 0.88rem;
        }

        .attempts-note.is-warning {
            border-color: var(--accent-mustard);
            color: var(--accent-mustard);
        }

        .admin-actions {
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
            color: var(--text-secondary);
            border: 2px solid var(--border-vintage);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.95rem;
        }

        .meta-line {
            margin-top: 1rem;
            color: var(--text-muted);
            font-size: 0.82rem;
        }

        @media (max-width: 480px) {
            .admin-title { font-size: 1.6rem; }
            .btn-primary { min-width: 100%; }
            .btn-secondary { width: 100%; }
        }
    </style>
</head>
<body>

    <div class="admin-container">
        <div class="admin-header">
            <h1 class="admin-title">⚙️ Admin</h1>
            <a href="index.php" class="admin-back">← Zurück</a>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert-banner alert-danger">
                <span>⚠️ <?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert-banner alert-success">
                <span>✓ <?php echo htmlspecialchars($success_message); ?></span>
            </div>
        <?php endif; ?>

        <form action="admin.php" method="POST" id="ai-settings-form">
            <div class="admin-card">
                <h2>KI-Modelle</h2>
                <p class="admin-hint">
                    Die Modelle werden in dieser Reihenfolge angefragt. Antwortet das erste nicht,
                    kommt das nächste dran. Sortiere sie mit den Pfeilen.
                </p>

                <ul class="model-list" id="model-list" hidden></ul>

                <div class="model-add" id="model-add" hidden>
                    <input type="text" id="model-new" class="form-input" placeholder="z.B. gemini-3.7-flash"
                           maxlength="64" autocomplete="off">
                    <button type="button" class="btn-secondary" id="model-add-btn">Hinzufügen</button>
                </div>

                <div id="model-fallback">
                    <label for="models" class="form-label">Ein Modell pro Zeile</label>
                    <textarea id="models" name="models" class="form-input" rows="5"><?php echo htmlspecialchars($models_text); ?></textarea>
                </div>
            </div>

            <div class="admin-card">
                <h2>Durchläufe</h2>
                <p class="admin-hint">
                    Wie oft die komplette Modell-Liste durchlaufen wird, bevor die Analyse aufgibt.
                </p>

                <label for="max_passes" class="form-label">Anzahl (1–<?php echo AI_MAX_PASSES_LIMIT; ?>)</label>
                <input type="number" id="max_passes" name="max_passes" class="form-input input-narrow"
                       min="1" max="<?php echo AI_MAX_PASSES_LIMIT; ?>" step="1"
                       value="<?php echo (int)$settings['max_passes']; ?>" required>

                <div class="attempts-note" id="attempts-note">
                    Maximal <strong id="attempts-count"><?php echo $total_attempts; ?></strong> Versuche
                    (Modelle × Durchläufe). Jeder Versuch wartet bis zu 25 Sekunden.
                </div>
            </div>

            <div class="admin-card">
                <div class="admin-actions">
                    <button type="submit" class="btn-primary">Speichern</button>
                    <button type="submit" name="reset_defaults" value="1" class="btn-secondary"
                            formnovalidate>Standardwerte</button>
                </div>

                <p class="meta-line">
                    <?php if ($settings['is_default']): ?>
                        Aktuell sind die Standardwerte aktiv.
                    <?php else: ?>
                        Zuletzt geändert
                        <?php if (!empty($settings['updated_at'])): ?>
                            am <?php echo htmlspecialchars(date('d.m.Y, H:i', strtotime($settings['updated_at']))); ?> Uhr
                        <?php endif; ?>
                        <?php if (!empty($settings['updated_by'])): ?>
                            von <?php echo htmlspecialchars($settings['updated_by']); ?>
                        <?php endif; ?>.
                    <?php endif; ?>
                </p>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var MAX_MODELS = <?php echo AI_MAX_MODELS; ?>;
        var VALID_MODEL = /^[A-Za-z0-9._-]{1,64}$/;

        var textarea = document.getElementById('models');
        var list = document.getElementById('model-list');
        var addRow = document.getElementById('model-add');
        var addInput = document.getElementById('model-new');
        var addBtn = document.getElementById('model-add-btn');
        var fallback = document.getElementById('model-fallback');
        var passes = document.getElementById('max_passes');
        var attemptsCount = document.getElementById('attempts-count');
        var attemptsNote = document.getElementById('attempts-note');

        // Progressive enhancement: without JS the textarea stays the input
        fallback.hidden = true;
        list.hidden = false;
        addRow.hidden = false;

        var models = textarea.value.split('\n').map(function (m) {
            return m.trim();
        }).filter(function (m) {
            return m !== '';
        });

        function updateAttempts() {
            var count = models.length * (parseInt(passes.value, 10) || 0);
            attemptsCount.textContent = count;
            attemptsNote.classList.toggle('is-warning', count > 9);
        }

        function sync() {
            textarea.value = models.join('\n');
            render();
            updateAttempts();
        }

        function move(index, delta) {
            var target = index + delta;
            if (target < 0 || target >= models.length) {
                return;
            }
            var moved = models.splice(index, 1)[0];
            models.splice(target, 0, moved);
            sync();
        }

        function button(label, className, title, disabled, onClick) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'model-btn ' + className;
            btn.textContent = label;
            btn.title = title;
            btn.setAttribute('aria-label', title);
            btn.disabled = disabled;
            btn.addEventListener('click', onClick);
            return btn;
        }

        function render() {
            list.textContent = '';

            models.forEach(function (model, index) {
                var row = document.createElement('li');
                row.className = 'model-row';

                var rank = document.createElement('span');
                rank.className = 'model-rank';
                rank.textContent = (index + 1) + '.';

                var name = document.createElement('span');
                name.className = 'model-name';
                name.textContent = model;

                row.appendChild(rank);
                row.appendChild(name);
                row.appendChild(button('↑', '', 'Nach oben', index === 0, function () {
                    move(index, -1);
                }));
                row.appendChild(button('↓', '', 'Nach unten', index === models.length - 1, function () {
                    move(index, 1);
                }));
                row.appendChild(button('✕', 'model-btn-remove', 'Entfernen', false, function () {
                    models.splice(index, 1);
                    sync();
                }));

                list.appendChild(row);
            });

            addInput.disabled = models.length >= MAX_MODELS;
            addBtn.disabled = models.length >= MAX_MODELS;
        }

        function addModel() {
            var value = addInput.value.trim();
            if (value === '' || !VALID_MODEL.test(value)) {
                addInput.focus();
                return;
            }
            if (models.indexOf(value) !== -1 || models.length >= MAX_MODELS) {
                addInput.value = '';
                return;
            }
            models.push(value);
            addInput.value = '';
            sync();
        }

        addBtn.addEventListener('click', addModel);
        addInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addModel();
            }
        });
        passes.addEventListener('input', updateAttempts);

        sync();
    })();
    </script>

</body>
</html>
