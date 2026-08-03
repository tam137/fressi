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
        if (isset($_POST['ajax_upload'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Konto deaktiviert.']);
            exit;
        }
        header('Location: logout.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Security check failed: " . $e->getMessage());
    die("Systemfehler. Zugriff verweigert.");
}

// PHP Backend AJAX Endpoint für Foto-Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    header('Content-Type: application/json');

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

    $webPath = 'uploads/photos/' . $newFileName;

    echo json_encode([
        'status' => 'success',
        'message' => 'Foto erfolgreich auf dem Server gespeichert!',
        'filename' => $newFileName,
        'path' => $webPath,
        'uploaded_at' => date('d.m.Y H:i:s')
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
    <link rel="stylesheet" href="css/style.css">
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
