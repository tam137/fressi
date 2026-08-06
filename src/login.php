<?php
require_once 'auth_helper.php';

// Check if user is already logged in or auto-logged in via remember me
if (check_remember_me()) {
    header('Location: index.php');
    exit;
}

$error_message = '';
$success_message = '';

if (isset($_GET['logged_out']) && $_GET['logged_out'] == 1) {
    $success_message = 'Erfolgreich abgemeldet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);

    if (empty($username) || empty($password)) {
        $error_message = 'Bitte gib Benutzernamen und Passwort ein.';
    } else {
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("SELECT * FROM accounts WHERE username = :username");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && $user['is_active']) {
                if (password_verify($password, $user['password_hash'])) {
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    $stmt_update = $pdo->prepare("UPDATE accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id");
                    $stmt_update->execute(['id' => $user['id']]);

                    if ($remember_me) {
                        set_remember_token($pdo, $user['id']);
                    }

                    header('Location: index.php');
                    exit;
                }
            }

            $error_message = 'Ungültiger Benutzername oder Passwort.';
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error_message = 'Ein Systemfehler ist aufgetreten. Bitte versuche es später noch einmal.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden — fressi</title>
    <!-- Favicon & Icons -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">

    <link rel="stylesheet" href="css/style.css">
    <style>
        html, body {
            height: 100%;
            overflow: auto;
        }

        .login-container {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            position: relative;
            width: 100%;
            box-sizing: border-box;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            padding: 2.5rem 2rem;
            position: relative;
            z-index: 10;
            background: var(--bg-card);
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-paper);
            box-sizing: border-box;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 1.75rem 1.25rem;
            }
            .login-header {
                margin-bottom: 1.5rem;
            }
            .login-title {
                font-size: 1.75rem;
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-logo {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 1rem;
        }

        .login-logo .logo-icon {
            width: 52px;
            height: 52px;
            background: var(--bg-secondary);
            border: 2px solid var(--accent-green);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            box-shadow: var(--shadow-sm);
        }

        .login-title {
            font-family: var(--font-heading);
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.4rem;
            color: var(--text-primary);
        }

        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .alert-banner {
            padding: 0.85rem 1.2rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border: 2px solid var(--border-vintage);
        }

        .alert-danger {
            background: var(--bg-secondary);
            border-color: var(--accent-terracotta);
            color: var(--accent-terracotta);
        }

        .alert-success {
            background: var(--bg-secondary);
            border-color: var(--accent-green);
            color: var(--accent-green);
        }

        .form-group {
            margin-bottom: 1.2rem;
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
            padding: 0.85rem 1.1rem;
            background: var(--bg-secondary);
            border: 2px solid var(--border-vintage);
            border-radius: var(--radius-sm);
            color: var(--text-primary);
            font-family: var(--font-body);
            font-size: 0.95rem;
            outline: none;
            transition: border-color var(--transition-fast);
        }

        .form-input:focus {
            border-color: var(--accent-green);
            background: var(--bg-card);
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 0.5rem;
            margin-bottom: 1.8rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
            cursor: pointer;
            user-select: none;
        }

        .checkbox-label input[type="checkbox"] {
            accent-color: var(--accent-green);
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .btn-login {
            width: 100%;
            padding: 0.9rem 1.5rem;
            border-radius: var(--radius-full);
            background: var(--accent-green);
            color: #ffffff;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            border: 2px solid var(--accent-green-hover);
            box-shadow: var(--shadow-paper);
            transition: all var(--transition-fast);
        }

        .btn-login:hover {
            background: var(--accent-green-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-paper-hover);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: none;
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <div class="logo-icon">🍲</div>
                </div>
                <h1 class="login-title">Willkommen zurück</h1>
                <p class="login-subtitle">Bitte melde dich an, um fortzufahren.</p>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="alert-banner alert-danger" id="alert-error">
                    <span>⚠️ <?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert-banner alert-success" id="alert-success">
                    <span>✓ <?php echo htmlspecialchars($success_message); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" autocomplete="off">
                <div class="form-group">
                    <label for="username" class="form-label">Benutzername</label>
                    <input type="text" id="username" name="username" class="form-input" placeholder="Dein Benutzername" required value="<?php echo htmlspecialchars($username ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Passwort</label>
                    <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required>
                </div>

                <div class="form-options">
                    <label class="checkbox-label">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <span>Angemeldet bleiben</span>
                    </label>
                </div>

                <button type="submit" class="btn-login" id="btn-login">
                    <span>Anmelden 🚀</span>
                </button>
            </form>
        </div>
    </div>

</body>
</html>
