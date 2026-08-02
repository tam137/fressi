<?php
require_once 'auth_helper.php';

// Unset session variables
$_SESSION = array();

// Delete remember_me cookie and DB token if set
if (isset($_COOKIE['remember_me'])) {
    $cookie_val = $_COOKIE['remember_me'];
    $parts = explode(':', $cookie_val);
    if (count($parts) === 2) {
        $selector = $parts[0];
        try {
            $pdo = get_db_connection();
            $stmt = $pdo->prepare("DELETE FROM remember_me_tokens WHERE selector = :selector");
            $stmt->execute(['selector' => $selector]);
        } catch (Exception $e) {
            error_log("Logout token delete error: " . $e->getMessage());
        }
    }
    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
}

// Destroy session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('Location: login.php?logged_out=1');
exit;
?>
