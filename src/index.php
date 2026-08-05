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
}/**
 * Normalisiert ein Bild für die Gemini API (konvertiert HEIC, PNG, WEBP, GIF bei Bedarf in JPEG).
 * 
 * @param string $filePath Pfad zur Bilddatei
 * @param string $mimeType MIME-Typ des Bildes
 * @return array ['path' => string, 'mime' => string, 'is_temp' => bool]
 */
function normalize_image_for_gemini($filePath, $mimeType) {
    $mimeLower = strtolower($mimeType);
    $isHeic = ($mimeLower === 'image/heic' || $mimeLower === 'image/heif');

    // HEIC/HEIF oder andere Formate bei Bedarf in sauberes JPEG konvertieren
    if ($isHeic || in_array($mimeLower, ['image/png', 'image/webp', 'image/gif'])) {
        $tempJpeg = $filePath . '_gemini_norm.jpg';

        // 1. Versuchen per Imagick (beste HEIC & Bild-Unterstützung)
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
                // Fallback auf GD
            }
        }

        // 2. Versuchen per GD Library
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
 * @return array ['success' => bool, 'text' => string|null, 'error' => string|null]
 */
function analyze_image_with_gemini($filePath, $mimeType) {
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

    // Bild für Gemini-Kompatibilität normalisieren (z. B. HEIC/PNG -> JPEG)
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

    $promptsFile = __DIR__ . '/prompts.php';
    $prompts = file_exists($promptsFile) ? require $promptsFile : [];
    $promptText = $prompts['image_analysis'] ?? null;

    if (empty($promptText)) {
        if ($isTempFile && file_exists($activePath)) { @unlink($activePath); }
        return [
            'success' => false,
            'error' => 'KI-Prompt konnte nicht geladen werden (src/prompts.php fehlt oder unvollständig).'
        ];
    }

    // Modelle versuchen: gemini-3.6-flash (Standard), gefolgt von weiteren aktuellen Flash-Modellen
    $models = ['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-2.5-flash', 'gemini-flash-latest'];
    $lastError = 'Unbekannter Fehler';

    foreach ($models as $model) {
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
            if ($httpCode === 404) {
                // Bei 404 nächstes Modell versuchen
                continue;
            }
            break;
        }

        $responseData = json_decode($response, true);
        $description = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!empty($description)) {
            if ($isTempFile && file_exists($activePath)) { @unlink($activePath); }
            return [
                'success' => true,
                'text' => trim($description)
            ];
        } else {
            $lastError = 'Keine gültige Text-Antwort von Gemini erhalten.';
        }
    }

    if ($isTempFile && file_exists($activePath)) { @unlink($activePath); }

    return [
        'success' => false,
        'error' => $lastError
    ];
}

// PHP Backend AJAX Endpoint für Foto-Upload
$isAjaxRequest = ($_SERVER['REQUEST_METHOD'] === 'POST') && (
    isset($_POST['ajax_upload']) ||
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

if ($isAjaxRequest) {
    header('Content-Type: application/json');

    // Abfangen von post_max_size Overflow (falls $_POST & $_FILES durch PHP gelöscht wurden)
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

    // 1. Dateigröße prüfen (max. 10 MB)
    $maxFileSize = 10 * 1024 * 1024;
    if ($fileSize > $maxFileSize) {
        echo json_encode(['status' => 'error', 'message' => 'Die Datei überschreitet das maximale Limit von 10 MB.']);
        exit;
    }

    // 2. MIME-Type per finfo_file prüfen
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

    // 3. Ziel-Ordner erstellen & sichern
    $uploadDir = __DIR__ . '/uploads/photos/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Server-Ordner konnte nicht angelegt werden.']);
            exit;
        }
    }

    // .htaccess Schutz im Upload-Ordner sicherstellen
    $htaccessFile = $uploadDir . '.htaccess';
    if (!file_exists($htaccessFile)) {
        $htaccessContent = "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\nSetHandler default-handler\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py\n<FilesMatch \"\\.(?i:jpe?g|png|webp|gif|heic|heif)$\">\n    Require all granted\n</FilesMatch>\n<FilesMatch \"^(?!\\.(?i:jpe?g|png|webp|gif|heic|heif)$)\">\n    Require all denied\n</FilesMatch>\n";
        @file_put_contents($htaccessFile, $htaccessContent);
    }

    // 4. Eindeutigen Dateinamen generieren (ohne Pfad-Traversal-Gefahr)
    $newFileName = 'photo_u' . $user['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;

    if (!move_uploaded_file($fileTmpPath, $targetPath)) {
        echo json_encode(['status' => 'error', 'message' => 'Fehler beim Speichern der Datei auf dem Server.']);
        exit;
    }

    // 5. Bildanalyse mittels Gemini API durchführen
    $aiResult = analyze_image_with_gemini($targetPath, $mimeType);

    if (!$aiResult['success']) {
        // Upload ablehnen und hochgeladene Datei löschen
        @unlink($targetPath);
        echo json_encode([
            'status' => 'error',
            'message' => 'Upload abgelehnt: KI-Bildanalyse fehlgeschlagen (' . $aiResult['error'] . ')'
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
        'ai_description' => $aiResult['text']
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>fressi — Kamera Foto Upload</title>
    <meta name="description" content="Nimm ein Foto auf und speichere es direkt auf deinem Server.">
    <meta name="theme-color" content="#0b0f19">

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
        <nav class="navbar glass-panel">
            <a href="#" class="brand-logo">
                <div class="logo-icon">📷</div>
                <span>fressi</span>
            </a>

            <div class="nav-actions">
                <div class="user-badge" title="Eingeloggt als <?php echo htmlspecialchars($user['username']); ?>">
                    <span>Hallo, <strong><?php echo htmlspecialchars(ucfirst($user['username'])); ?></strong></span>
                </div>
                <a href="logout.php" class="chip-btn" title="Abmelden" style="background: rgba(247, 37, 133, 0.15); border-color: rgba(247, 37, 133, 0.3); color: #ff4d6d;">
                    Abmelden 🚪
                </a>
                <button id="theme-toggle" class="theme-toggle" title="Design-Modus umschalten">
                    🌙
                </button>
            </div>
        </nav>
    </header>

    <!-- Main Content Container (Centered & Offset for Fixed Header) -->
    <main class="camera-main">
        <section class="photo-capture-section">
            <div class="hero-glow"></div>
            
            <div class="photo-card glass-panel">
                <div class="card-header">
                    <h1 class="card-title">Foto <span class="gradient-text">aufnehmen</span></h1>
                    <p class="card-subtitle">
                        Tippe auf den Button, um die Kamera zu öffnen. Das Foto wird direkt auf dem Server gespeichert.
                    </p>
                </div>

                <!-- Hidden native camera input -->
                <form id="photo-upload-form" enctype="multipart/form-data">
                    <input type="file" id="photo-input" name="photo" accept="image/*" capture="environment" hidden>
                </form>

                <!-- Initial Upload Trigger Area -->
                <div id="dropzone" class="upload-dropzone">
                    <div class="dropzone-icon">📸</div>
                    <button type="button" id="trigger-btn" class="btn-primary camera-trigger-btn">
                        Foto aufnehmen 📷
                    </button>
                </div>

                <!-- Status Feedback Message -->
                <div id="status-box" class="status-box" style="display: none;"></div>
            </div>
        </section>
    </main>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- JavaScript -->
    <script src="js/app.js"></script>
</body>
</html>
