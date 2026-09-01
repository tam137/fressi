<?php
// Example database configuration for the Foodie (fressi) web application.
// Copy this file to `/var/www/fressi/config.php` (or project root `/config.php`)
// and replace the placeholder password with your actual database password.

$db_config = [
    'host' => '127.0.0.1',
    'port' => '5433',
    'dbname' => 'fressi',
    'user' => 'web_login_user',
    'password' => 'YOUR_DATABASE_PASSWORD_HERE'
];

// Google Gemini API configuration
// Replace placeholder with your actual Gemini API key from Google AI Studio.
// Users can store their own key on settings.php, which then takes precedence.
$gemini_key = 'YOUR_GEMINI_API_KEY_HERE';

// Secret used to encrypt user secrets (e.g. personal Gemini keys) in the database.
// Generate one with: php -r "echo bin2hex(random_bytes(32));"
// If left unset, the database password above is used as key material instead.
// Changing it makes already stored user keys unreadable — users must enter them again.
$app_secret = 'YOUR_RANDOM_APP_SECRET_HERE';
?>
