<?php
// FRESSI — Single Page Responsive Web Application
require_once 'auth_helper.php';

// Authentifizierung erzwingen (Sitzung oder Remember-Me Cookie)
if (!check_remember_me()) {
    if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax_upload'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Nicht angemeldet. Session abgelaufen.']);
        exit;
    }
    header('Location: login.php');
    exit;
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT id, username, is_active, role FROM accounts WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax_upload'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Konto deaktiviert.']);
            exit;
        }
        header('Location: logout.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Security check failed: " . $e->getMessage());
    if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax_upload'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Systemfehler. Zugriff verweigert.']);
        exit;
    }
    die("Systemfehler. Zugriff verweigert.");
}

/**
 * Normalisiert ein Bild für die Gemini API (konvertiert HEIC, PNG, WEBP, GIF bei Bedarf in JPEG).
 * 
 * @param string $filePath Pfad zur Bilddatei
 * @param string $mimeType MIME-Typ des Bildes
 * @return array ['path' => string, 'mime' => string, 'is_temp' => bool]
 */
function normalize_image_for_gemini($filePath, $mimeType) {
    $mimeLower = strtolower($mimeType);
    $isHeic = ($mimeLower === 'image/heic' || $mimeLower === 'image/heif');

    if ($isHeic || in_array($mimeLower, ['image/png', 'image/webp', 'image/gif'])) {
        $tempJpeg = $filePath . '_gemini_norm.jpg';

        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($filePath);
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(85);
                $imagick->writeImage($tempJpeg);
                $imagick->clear();
                $imagick->destroy();
                return ['path' => $tempJpeg, 'mime' => 'image/jpeg', 'is_temp' => true];
            } catch (Exception $e) {
            }
        }

        if (function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($filePath);
            if ($raw !== false) {
                $img = @imagecreatefromstring($raw);
                if ($img !== false) {
                    if (@imagejpeg($img, $tempJpeg, 85)) {
                        imagedestroy($img);
                        return ['path' => $tempJpeg, 'mime' => 'image/jpeg', 'is_temp' => true];
                    }
                    imagedestroy($img);
                }
            }
        }
    }

    return ['path' => $filePath, 'mime' => $mimeType, 'is_temp' => false];
}

/**
 * Analysiert ein Bild mittels Google Gemini API.
 * 
 * @param string $filePath Pfad zur Bilddatei
 * @param string $mimeType MIME-Typ des Bildes
 * @param string $promptText Prompt für die Analyse
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function analyze_image_with_gemini($filePath, $mimeType, $promptText) {
    global $gemini_key, $config, $db_config;
    $apiKey = $gemini_key ?? ($config['gemini_key'] ?? ($db_config['gemini_key'] ?? (getenv('gemini_key') ?: (getenv('GEMINI_KEY') ?: null))));

    if (empty($apiKey) || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
        return [
            'success' => false,
            'error' => 'KI-Analyse nicht eingerichtet.'
        ];
    }

    if (!file_exists($filePath)) {
        return [
            'success' => false,
            'error' => 'Bilddatei konnte für die Analyse nicht gefunden werden.'
        ];
    }

    $normalized = normalize_image_for_gemini($filePath, $mimeType);
    $activePath = $normalized['path'];
    $activeMime = $normalized['mime'];
    $isTempFile = $normalized['is_temp'];

    $imageData = file_get_contents($activePath);
    if ($imageData === false) {
        if ($isTempFile && file_exists($activePath)) { @unlink($activePath); }
        return [
            'success' => false,
            'error' => 'Bilddatei konnte nicht gelesen werden.'
        ];
    }

    $base64Image = base64_encode($imageData);

    $models = ['gemini-3.6-flash', 'gemini-3.5-flash'];
    $maxPasses = 2;
    $attemptCount = 0;
    $lastError = 'Unbekannter Fehler';
    $startTime = microtime(true);

    for ($pass = 1; $pass <= $maxPasses; $pass++) {
        foreach ($models as $model) {
            $attemptCount++;

            if ($attemptCount > 1) {
                sleep(1);
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $promptText
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $activeMime,
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ],
                'tools' => [
                    ['googleSearch' => (object)[]]
                ],
                'generationConfig' => [
                    'response_mime_type' => 'application/json'
                ]
            ];

            $jsonPayload = json_encode($payload);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($curlErr)) {
                $lastError = 'Verbindung zur Gemini API fehlgeschlagen: ' . ($curlErr ?: 'Unbekannter Fehler');
                continue;
            }

            if ($httpCode !== 200) {
                $errorData = json_decode($response, true);
                $msg = $errorData['error']['message'] ?? ('HTTP Status ' . $httpCode);
                $lastError = 'Gemini API Fehler (' . $model . '): ' . $msg;
                if ($httpCode === 401 || $httpCode === 403) {
                    break 2;
                }
                continue;
            }

            $responseData = json_decode($response, true);
            $rawText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if (!empty($rawText)) {
                if ($isTempFile && file_exists($activePath)) { @unlink($activePath); }

                // Clean Markdown JSON block wrapper if returned
                $cleanedJson = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
                $cleanedJson = preg_replace('/\s*```$/', '', $cleanedJson);

                $parsedData = json_decode($cleanedJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($parsedData)) {
                    $durationMs = (int)round((microtime(true) - $startTime) * 1000);
                    return [
                        'success' => true,
                        'data' => $parsedData,
                        'model' => $model,
                        'attempts' => $attemptCount,
                        'duration_ms' => $durationMs
                    ];
                } else {
                    $lastError = 'KI-Antwort war kein gültiges JSON: ' . json_last_error_msg();
                }
            } else {
                $lastError = 'Keine gültige Text-Antwort von Gemini erhalten.';
            }
        }
    }

    if ($isTempFile && file_exists($activePath)) { @unlink($activePath); }

    return [
        'success' => false,
        'error' => "Fehler nach {$attemptCount} Versuchen: " . $lastError
    ];
}

$isAjaxRequest = ($_SERVER['REQUEST_METHOD'] === 'POST') && (
    isset($_POST['ajax_upload']) ||
    isset($_POST['action']) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

if ($isAjaxRequest) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? 'upload_photo';

    // Handler 1: Refinement Loop (Nutzer hat Zutaten/Text geändert)
    if ($action === 'refine_summary') {
        $photoPath = $_POST['photo_path'] ?? '';
        $previousRating = $_POST['previous_rating'] ?? '';
        $ingredientsInput = $_POST['ingredients'] ?? '';
        $notes = $_POST['notes'] ?? '';
        $portion = (int)($_POST['portion'] ?? 100);
        $calories = (int)($_POST['calories'] ?? 0);

        // Security check for photo path
        $uploadsDir = realpath(__DIR__ . '/uploads/photos');
        $absPath = realpath(__DIR__ . '/' . $photoPath);

        if (!$absPath || !$uploadsDir || strpos($absPath, $uploadsDir) !== 0 || !file_exists($absPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Das zugehörige Foto konnte auf dem Server nicht gefunden werden.']);
            exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $absPath);
        finfo_close($finfo);

        $promptsFile = __DIR__ . '/prompts.php';
        $prompts = file_exists($promptsFile) ? require $promptsFile : [];
        $templatePrompt = $prompts['refine_analysis'] ?? '';

        if (empty($templatePrompt)) {
            echo json_encode(['status' => 'error', 'message' => 'Refinement-Prompt fehlt in prompts.php']);
            exit;
        }

        $ingredientsStr = is_array($ingredientsInput) ? implode(', ', $ingredientsInput) : (string)$ingredientsInput;

        $promptText = str_replace(
            ['{PREVIOUS_RATING}', '{USER_INGREDIENTS}', '{USER_NOTES}', '{USER_PORTION}', '{USER_CALORIES}'],
            [$previousRating, $ingredientsStr, $notes ?: 'Keine zusätzlichen Anmerkungen', $portion, $calories],
            $templatePrompt
        );

        $aiResult = analyze_image_with_gemini($absPath, $mimeType, $promptText);

        if (!$aiResult['success']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'KI-Neubewertung fehlgeschlagen: ' . $aiResult['error']
            ]);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Wertigkeit erfolgreich von KI aktualisiert!',
            'data' => $aiResult['data'],
            'model' => $aiResult['model'] ?? null,
            'attempts' => $aiResult['attempts'] ?? null,
            'duration_ms' => $aiResult['duration_ms'] ?? null
        ]);
        exit;
    }

    // Handler 2: Mahlzeit in Datenbak speichern (User klickt "Speichern 💾")
    if ($action === 'save_meal') {
        ensure_meals_table_exists($pdo);

        $consumedAtInput = $_POST['consumed_at'] ?? '';
        if (!empty($consumedAtInput)) {
            $timestamp = strtotime($consumedAtInput);
            $consumedAt = ($timestamp !== false) ? date('Y-m-d H:i:sP', $timestamp) : date('Y-m-d H:i:sP');
        } else {
            $consumedAt = date('Y-m-d H:i:sP');
        }

        $title = trim($_POST['title'] ?? 'Mahlzeit');
        $photoPath = $_POST['photo_path'] ?? '';
        $imageFilename = basename($photoPath);
        $aiModel = $_POST['ai_model'] ?? null;
        $aiAttempts = (int)($_POST['ai_attempts'] ?? 1);
        $processingTimeMs = (int)($_POST['processing_time_ms'] ?? 0);
        $ingredientsInput = $_POST['ingredients'] ?? '';
        $ingredientsStr = is_array($ingredientsInput) ? implode(', ', $ingredientsInput) : (string)$ingredientsInput;
        $healthRating = $_POST['health_rating'] ?? '';
        $calories = (int)($_POST['calories'] ?? 0);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO meals (account_id, consumed_at, title, image_filename, ai_model, ai_attempts, processing_time_ms, ingredients, health_rating, calories)
                VALUES (:account_id, :consumed_at, :title, :image_filename, :ai_model, :ai_attempts, :processing_time_ms, :ingredients, :health_rating, :calories)
            ");
            $stmt->execute([
                'account_id' => $_SESSION['user_id'],
                'consumed_at' => $consumedAt,
                'title' => $title,
                'image_filename' => $imageFilename,
                'ai_model' => $aiModel,
                'ai_attempts' => $aiAttempts,
                'processing_time_ms' => $processingTimeMs,
                'ingredients' => $ingredientsStr,
                'health_rating' => $healthRating,
                'calories' => $calories
            ]);

            echo json_encode([
                'status' => 'success',
                'message' => 'Mahlzeit erfolgreich in der Datenbank gespeichert! 💾'
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Failed to save meal: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Speichern der Mahlzeit in der Datenbank.'
            ]);
            exit;
        }
    }

    // Handler 3: Erstmaliger Photo Upload & Analyse
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Die hochgeladene Datei überschreitet die maximale Server-Uploadgröße (post_max_size).']);
        exit;
    }

    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['photo']['error'] ?? 'missing';
        $errorMsg = 'Keine Datei empfangen oder Fehler beim Upload (Code: ' . $errorCode . ').';
        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            $errorMsg = 'Die Datei ist zu groß für den Upload auf dem Server.';
        }
        echo json_encode(['status' => 'error', 'message' => $errorMsg]);
        exit;
    }

    $fileTmpPath = $_FILES['photo']['tmp_name'];
    $fileSize = $_FILES['photo']['size'];

    $maxFileSize = 10 * 1024 * 1024;
    if ($fileSize > $maxFileSize) {
        echo json_encode(['status' => 'error', 'message' => 'Die Datei überschreitet das maximale Limit von 10 MB.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/heic' => 'heic',
        'image/heif' => 'heif'
    ];

    if (!array_key_exists($mimeType, $allowedMimeTypes)) {
        echo json_encode(['status' => 'error', 'message' => 'Ungültiges Dateiformat (' . htmlspecialchars($mimeType) . '). Nur Bilder (JPG, PNG, WEBP, GIF, HEIC) sind erlaubt.']);
        exit;
    }

    $ext = $allowedMimeTypes[$mimeType];

    $uploadDir = __DIR__ . '/uploads/photos/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Server-Ordner konnte nicht angelegt werden.']);
            exit;
        }
    }

    $htaccessFile = $uploadDir . '.htaccess';
    if (!file_exists($htaccessFile)) {
        $htaccessContent = "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\nSetHandler default-handler\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py\n<FilesMatch \"\\.(?i:jpe?g|png|webp|gif|heic|heif)$\">\n    Require all granted\n</FilesMatch>\n<FilesMatch \"^(?!\\.(?i:jpe?g|png|webp|gif|heic|heif)$)\">\n    Require all denied\n</FilesMatch>\n";
        @file_put_contents($htaccessFile, $htaccessContent);
    }

    $newFileName = 'photo_u' . $user['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (!move_uploaded_file($fileTmpPath, $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Fehler beim Speichern der Datei auf dem Server.']);
        exit;
    }

    $promptsFile = __DIR__ . '/prompts.php';
    $prompts = file_exists($promptsFile) ? require $promptsFile : [];
    $promptText = $prompts['image_analysis'] ?? '';

    if (empty($promptText)) {
        @unlink($targetPath);
        echo json_encode(['status' => 'error', 'message' => 'KI-Prompt konnte nicht geladen werden (prompts.php)']);
        exit;
    }

    $aiResult = analyze_image_with_gemini($targetPath, $mimeType, $promptText);

    if (!$aiResult['success']) {
        @unlink($targetPath);
        echo json_encode([
            'status' => 'error',
            'message' => 'Upload abgelehnt: KI-Bildanalyse fehlgeschlagen (' . $aiResult['error'] . ')'
        ]);
        exit;
    }

    $data = $aiResult['data'];
    if (isset($data['is_food']) && $data['is_food'] === false) {
        @unlink($targetPath);
        $errMsg = $data['error_message'] ?? '⚠️ Kein Essen, Getränk oder Lebensmittel-Etikett erkannt.';
        echo json_encode([
            'status' => 'error',
            'message' => $errMsg
        ]);
        exit;
    }

    $webPath = 'uploads/photos/' . $newFileName;

    echo json_encode([
        'status' => 'success',
        'message' => 'Foto erfolgreich analysiert!',
        'filename' => $newFileName,
        'path' => $webPath,
        'uploaded_at' => date('d.m.Y H:i:s'),
        'data' => $data,
        'model' => $aiResult['model'] ?? null,
        'attempts' => $aiResult['attempts'] ?? null,
        'duration_ms' => $aiResult['duration_ms'] ?? null
    ]);
    exit;
}

// Determine build timestamp (Git commit time if available, otherwise file modification time fallback)
$buildTimestamp = null;
if (function_exists('exec') && is_dir(__DIR__ . '/../.git')) {
    $gitTimestamp = @exec('git -C ' . escapeshellarg(__DIR__) . ' log -1 --format="%ct"');
    if (is_numeric($gitTimestamp) && (int)$gitTimestamp > 0) {
        $buildTimestamp = (int)$gitTimestamp;
    }
}
if (!$buildTimestamp) {
    $buildTimestamp = max(
        filemtime(__DIR__ . '/index.php'),
        filemtime(__DIR__ . '/js/app.js'),
        filemtime(__DIR__ . '/css/style.css')
    );
}
$buildDateFormatted = date('d.m.Y, H:i', $buildTimestamp) . ' Uhr';
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>fressi — Kamera Foto Upload</title>
    <meta name="description" content="Nimm ein Foto auf und speichere es direkt auf deinem Server.">
    <meta name="theme-color" content="#f9f6f0">

    <!-- Favicon & Icons -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
</head>
<body>

    <!-- Navigation Header -->
    <header>
        <nav class="navbar">
            <a href="#" class="brand-logo">
                <div class="logo-icon">📷</div>
                <span>fressi</span>
            </a>

            <div class="nav-actions">
                <div class="user-badge" title="Eingeloggt als <?php echo htmlspecialchars($user['username']); ?>">
                    <span>Hallo, <strong><?php echo htmlspecialchars(ucfirst($user['username'])); ?></strong></span>
                </div>
                <a href="logout.php" class="btn-logout" title="Abmelden">
                    <span>Abmelden 🚪</span>
                </a>
                <button id="theme-toggle" class="theme-toggle" title="Design-Modus umschalten">
                    🌙
                </button>
            </div>
        </nav>
    </header>

    <!-- Main Content Container -->
    <main class="camera-main">
        <section class="photo-capture-section">
            <!-- Initial Upload Card -->
            <div id="photo-card" class="photo-card">
                <div class="card-header">
                    <h1 class="card-title">Foto <span class="accent-text">aufnehmen</span></h1>
                    <p class="card-subtitle">
                        Tippe auf den Button, um die Kamera zu öffnen. Das Foto wird direkt von der KI analysiert.
                    </p>
                </div>

                <!-- Hidden native camera and gallery inputs -->
                <form id="photo-upload-form" enctype="multipart/form-data">
                    <input type="file" id="photo-input-camera" name="photo" accept="image/*" capture="environment" style="display: none;">
                    <input type="file" id="photo-input-gallery" name="photo" accept="image/*" style="display: none;">
                </form>

                <!-- Initial Upload Trigger Area -->
                <div id="dropzone" class="upload-dropzone">
                    <div class="dropzone-icon">📸</div>
                    <div class="dropzone-buttons">
                        <label for="photo-input-camera" id="trigger-camera-btn" class="btn-primary camera-trigger-btn">
                            Foto aufnehmen 📷
                        </label>
                        <label for="photo-input-gallery" id="trigger-gallery-btn" class="btn-secondary gallery-trigger-btn">
                            Aus Galerie wählen 🖼️
                        </label>
                    </div>
                </div>

                <!-- Status Feedback Message -->
                <div id="status-box" class="status-box" style="display: none;"></div>
            </div>

            <!-- Validation & Refinement Card (Hidden by default) -->
            <div id="validation-card" class="photo-card validation-card" style="display: none;">
                <div class="card-header">
                    <div class="meal-preview-header">
                        <img id="meal-photo-preview" src="" alt="Mahlzeit Vorschau" class="meal-photo-thumb">
                        <div>
                            <h2 id="meal-title" class="card-title meal-title">Mahlzeit</h2>
                            <span class="status-header-badge">KI-Analyse Überprüfung</span>
                        </div>
                    </div>
                </div>

                <form id="validation-form" class="validation-form" onsubmit="return false;">
                    <!-- Date & Time Input (15-min interval) -->
                    <div class="form-group">
                        <label for="field-datetime" class="form-label">📅 Datum & Uhrzeit der Anfrage</label>
                        <input type="datetime-local" id="field-datetime" class="form-control" step="900">
                    </div>

                    <!-- Editable Ingredients -->
                    <div class="form-group">
                        <label class="form-label">🥗 Zutaten & Bestandteile <small>(bearbeitbar)</small></label>
                        <div id="ingredients-container" class="ingredients-container">
                            <div id="ingredients-chips" class="ingredients-chips"></div>
                            <div class="add-ingredient-box">
                                <input type="text" id="add-ingredient-input" class="form-control form-control-sm" placeholder="Neue Zutat eingeben...">
                                <button type="button" id="btn-add-ingredient" class="btn-sm btn-outline">+ Hinzufügen</button>
                            </div>
                        </div>
                    </div>

                    <!-- Read-only Health Rating / Value Description -->
                    <div class="form-group">
                        <label class="form-label">💚 Wertigkeit & Wohlbefinden <small>(von KI berechnet)</small></label>
                        <div id="health-rating-display" class="health-rating-box"></div>
                        <div id="ai-model-info" class="ai-model-info" style="display: none;"></div>
                    </div>

                    <!-- Portion & Calories Row -->
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label for="field-portion" class="form-label">🍽️ Verzehrmenge</label>
                            <select id="field-portion" class="form-control">
                                <option value="10">10 %</option>
                                <option value="20">20 %</option>
                                <option value="30">30 %</option>
                                <option value="40">40 %</option>
                                <option value="50">50 %</option>
                                <option value="60">60 %</option>
                                <option value="70">70 %</option>
                                <option value="80">80 %</option>
                                <option value="90">90 %</option>
                                <option value="100" selected>100 % (Gesamt)</option>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label for="field-calories" class="form-label">🔥 Kalorien (insgesamt)</label>
                            <div class="input-unit-wrapper">
                                <input type="number" id="field-calories" class="form-control" min="0" step="5">
                                <span class="unit-label">kcal</span>
                            </div>
                        </div>
                    </div>

                    <!-- Free Text Notes / Missing Ingredients -->
                    <div class="form-group">
                        <label for="field-notes" class="form-label">📝 Zusätzliche Infos / Nicht erkannte Zutaten</label>
                        <textarea id="field-notes" class="form-control" rows="3" placeholder="z. B. 1 EL Olivenöl extra, oder Hafermilch statt Kuhmilch..."></textarea>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons-group">
                        <button type="button" id="btn-save" class="btn-primary btn-save">
                            Speichern 💾
                        </button>
                        <button type="button" id="btn-discard" class="btn-secondary btn-discard">
                            Verwerfen 🗑️
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Build Info Footer -->
        <footer class="build-info-footer">
            <small>Letzter Build: <?php echo htmlspecialchars($buildDateFormatted); ?></small>
        </footer>
    </main>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- JavaScript -->
    <script src="js/app.js"></script>
</body>
</html>
