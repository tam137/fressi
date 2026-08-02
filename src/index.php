<?php
// FRESSI — Single Page Responsive Web Application
require_once 'auth_helper.php';

// Authentifizierung erzwingen (Sitzung oder Remember-Me Cookie)
if (!check_remember_me()) {
    header('Location: login.php');
    exit;
}

try {
    $pdo = get_db_connection();
    // Aktuellen Benutzerstatus prüfen (ob Konto aktiv ist)
    $stmt = $pdo->prepare("SELECT id, username, is_active, role FROM accounts WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        header('Location: logout.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Security check failed: " . $e->getMessage());
    die("Systemfehler. Zugriff verweigert.");
}

// PHP Backend AJAX Endpoint Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_contact'])) {
    header('Content-Type: application/json');
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$name || !$email || !$message) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte fülle alle Felder korrekt aus.']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Vielen Dank, ' . htmlspecialchars($name) . '! Deine Nachricht wurde erfolgreich übermittelt.'
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>fressi — Deine kulinarische Inspiration & Rezept-Explorer</title>
    <meta name="description" content="Fressi ist deine moderne Rezept-Plattform & Meal-Planner. Entdecke schnelle Gerichte, leckere Ideen und frische Inspiration für jeden Tag.">
    <meta name="theme-color" content="#0b0f19">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- Navigation Header -->
    <header>
        <nav class="navbar glass-panel">
            <a href="#" class="brand-logo">
                <div class="logo-icon">🍲</div>
                <span>fressi</span>
            </a>
            
            <ul class="nav-links">
                <li><a href="#hero" class="nav-link active">Home</a></li>
                <li><a href="#recipes" class="nav-link">Rezepte</a></li>
                <li><a href="#planner" class="nav-link">Essensplaner</a></li>
                <li><a href="#contact" class="nav-link">Kontakt</a></li>
            </ul>

            <div class="nav-actions">
                <div class="user-badge" title="Eingeloggt als <?php echo htmlspecialchars($user['username']); ?>">
                    <span>Hallo, <strong><?php echo htmlspecialchars(ucfirst($user['username'])); ?></strong></span>
                </div>
                <a href="logout.php" class="chip-btn" title="Abmelden" style="background: rgba(247, 37, 133, 0.15); border-color: rgba(247, 37, 133, 0.3); color: #ff4d6d;">
                    Abmelden 🚪
                </a>
                <button id="fav-toggle-btn" class="fav-toggle-btn" title="Gespeicherte Favoriten">
                    ❤️
                    <span id="fav-count" class="fav-badge">0</span>
                </button>
                <button id="theme-toggle" class="theme-toggle" title="Design-Modus umschalten">
                    🌙
                </button>
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section id="hero" class="hero-section">
        <div class="hero-glow"></div>
        <div class="container">
            <div class="hero-grid">
                <div class="hero-text-content">
                    <div class="hero-tag">
                        ✨ Dein smarter Rezept-Guide
                    </div>
                    <h1 class="hero-title">
                        Was essen wir <span class="gradient-text">heute?</span>
                    </h1>
                    <p class="hero-subtitle">
                        Keine Lust auf stundenlanges Überlegen? Entdecke leckere Rezepte, erstelle deine Favoritenliste und lass dich vom interaktiven Essen-Generator inspirieren.
                    </p>
                </div>

                <div class="hero-interactive-card glass-panel" id="planner">
                    <div class="interactive-header">
                        <h3 class="interactive-title">🎲 Was koche ich heute?</h3>
                    </div>
                    <p style="font-size: 0.9rem; color: var(--text-secondary);">
                        Keine Idee? Klicke auf den Button und fressi wählt ein perfektes Gericht für dich aus!
                    </p>

                    <div id="randomizer-result" class="randomizer-result">
                        <span style="color: var(--text-muted);">Bereit für die Inspiration?</span>
                    </div>

                    <button id="spin-btn" class="spin-wheel-btn">
                        <span>Gericht mischen</span> 🚀
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Filter & Recipe Explorer Section -->
    <main id="recipes" class="container">
        <section class="filter-section">
            <div class="filter-wrapper glass-panel">
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="search-input" class="search-input" placeholder="Zutat oder Gericht suchen (z.B. Pasta, Lachs, Vegan)...">
                </div>

                <div class="category-chips">
                    <button class="chip-btn active" data-category="all">Alle</button>
                    <button class="chip-btn" data-category="quick">⚡ Schnell & Einfach</button>
                    <button class="chip-btn" data-category="lowcarb">🥑 Low Carb</button>
                    <button class="chip-btn" data-category="vegan">🌱 Vegan</button>
                    <button class="chip-btn" data-category="comfort">🍝 Comfort Food</button>
                </div>
            </div>
        </section>

        <!-- Recipe Grid -->
        <section class="recipe-grid" id="recipe-grid">
            <!-- Dynamic Recipe Cards Rendered via JS -->
        </section>

        <!-- Contact Section -->
        <section id="contact" class="contact-section">
            <div class="contact-card glass-panel">
                <div>
                    <h2 style="font-size: 2rem; margin-bottom: 0.8rem;">Hast du Fragen oder Rezept-Ideen?</h2>
                    <p style="color: var(--text-secondary);">
                        Schreib uns direkt! Wir freuen uns über Feedback, Vorschläge oder neue Rezeptideen für fressi.
                    </p>
                </div>

                <form id="contact-form">
                    <div class="form-group">
                        <label for="name" class="form-label">Dein Name</label>
                        <input type="text" id="name" name="name" class="form-input" placeholder="Z. B. Alex" required>
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Deine E-Mail-Adresse</label>
                        <input type="email" id="email" name="email" class="form-input" placeholder="alex@beispiel.de" required>
                    </div>

                    <div class="form-group">
                        <label for="message" class="form-label">Deine Nachricht</label>
                        <textarea id="message" name="message" class="form-textarea" rows="4" placeholder="Deine Idee oder Frage..." required></textarea>
                    </div>

                    <button type="submit" class="btn-primary">
                        Nachricht senden ✉️
                    </button>
                </form>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>© <?php echo date('Y'); ?> fressi — Dein kulinarischer Begleiter. Erstellt mit ❤️ für deinen Server.</p>
        </div>
    </footer>

    <!-- Recipe Detail Modal -->
    <div id="modal-overlay" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-content glass-panel">
            <button id="modal-close" class="modal-close" aria-label="Schließen">✕</button>
            <div id="modal-body"></div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- JavaScript Bundle -->
    <script src="js/app.js"></script>
</body>
</html>
