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

    $models = ['gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash'];
    $maxPasses = 3;
    $attemptCount = 0;
    $lastError = 'Unbekannter Fehler';
    $startTime = microtime(true);

    for ($pass = 1; $pass <= $maxPasses; $pass++) {
        foreach ($models as $model) {
            $attemptCount++;

            if ($attemptCount > 1) {
                sleep(2);
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
            unset($ch);

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

$isAjaxRequest = (
    ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') && (
        isset($_POST['ajax_upload']) ||
        isset($_POST['action']) ||
        isset($_GET['action']) ||
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    )
);

if ($isAjaxRequest) {
    header('Content-Type: application/json');

    $action = $_REQUEST['action'] ?? 'upload_photo';

    // Handler: Favoriten abrufen
    if ($action === 'get_favorites') {
        ensure_favorites_table_exists($pdo);

        try {
            $stmt = $pdo->prepare("
                SELECT id, meal_id, title, image_filename, ingredients, health_rating, calories, portion, consumed_at, created_at, last_used_at
                FROM favorites
                WHERE account_id = :account_id
                ORDER BY COALESCE(last_used_at, created_at) DESC, id DESC
            ");
            $stmt->execute(['account_id' => $_SESSION['user_id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $favorites = [];
            foreach ($rows as $row) {
                $imgWebUrl = null;
                if (!empty($row['image_filename'])) {
                    $imgPath = __DIR__ . '/uploads/photos/' . $row['image_filename'];
                    if (file_exists($imgPath)) {
                        $imgWebUrl = 'uploads/photos/' . $row['image_filename'];
                    }
                }

                $favorites[] = [
                    'id' => (int)$row['id'],
                    'meal_id' => (int)$row['meal_id'],
                    'title' => $row['title'],
                    'image_filename' => $row['image_filename'],
                    'image_url' => $imgWebUrl,
                    'ingredients' => $row['ingredients'],
                    'health_rating' => $row['health_rating'],
                    'calories' => (int)$row['calories'],
                    'portion' => (int)($row['portion'] ?? 100),
                    'consumed_at' => $row['consumed_at']
                ];
            }

            echo json_encode([
                'status' => 'success',
                'favorites' => $favorites
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Failed to fetch favorites: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Abrufen der Favorieten.'
            ]);
            exit;
        }
    }

    // Handler: Historie abrufen (7-Tage-Pagination)
    if ($action === 'get_history') {
        ensure_meals_table_exists($pdo);
        ensure_favorites_table_exists($pdo);

        $page = max(0, (int)($_REQUEST['page'] ?? 0));
        $daysPerPage = 7;

        // Base today timestamp (midnight)
        $todayTimestamp = strtotime('today');

        // Page 0 = today down to 6 days ago (7 days total)
        $endDayOffset = $page * $daysPerPage;
        $startDayOffset = ($page + 1) * $daysPerPage - 1;

        $endTimestamp = strtotime("-{$endDayOffset} days", $todayTimestamp);
        $startTimestamp = strtotime("-{$startDayOffset} days", $todayTimestamp);

        $endDateStr = date('Y-m-d 23:59:59', $endTimestamp);
        $startDateStr = date('Y-m-d 00:00:00', $startTimestamp);

        try {
            $stmt = $pdo->prepare("
                SELECT m.id, m.account_id, m.consumed_at, m.title, m.image_filename, m.ai_model, m.ai_attempts, m.processing_time_ms, m.ingredients, m.health_rating, m.calories, m.portion, m.created_at,
                       (CASE WHEN f.id IS NOT NULL THEN 1 ELSE 0 END) AS is_favorite
                FROM meals m
                LEFT JOIN favorites f ON f.meal_id = m.id AND f.account_id = m.account_id
                WHERE m.account_id = :account_id
                  AND m.consumed_at >= :start_date
                  AND m.consumed_at <= :end_date
                ORDER BY m.consumed_at DESC, m.id DESC
            ");
            $stmt->execute([
                'account_id' => $_SESSION['user_id'],
                'start_date' => $startDateStr,
                'end_date' => $endDateStr
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group meals by date (Y-m-d)
            $mealsByDate = [];
            foreach ($rows as $row) {
                $dateKey = date('Y-m-d', strtotime($row['consumed_at']));
                if (!isset($mealsByDate[$dateKey])) {
                    $mealsByDate[$dateKey] = [];
                }

                $imgWebUrl = null;
                if (!empty($row['image_filename'])) {
                    $imgPath = __DIR__ . '/uploads/photos/' . $row['image_filename'];
                    if (file_exists($imgPath)) {
                        $imgWebUrl = 'uploads/photos/' . $row['image_filename'];
                    }
                }

                $mealsByDate[$dateKey][] = [
                    'id' => (int)$row['id'],
                    'title' => $row['title'],
                    'consumed_at' => $row['consumed_at'],
                    'time_formatted' => date('H:i', strtotime($row['consumed_at'])),
                    'full_datetime_formatted' => date('d.m.Y, H:i', strtotime($row['consumed_at'])) . ' Uhr',
                    'calories' => (int)$row['calories'],
                    'portion' => (int)($row['portion'] ?? 100),
                    'ingredients' => $row['ingredients'],
                    'health_rating' => $row['health_rating'],
                    'image_filename' => $row['image_filename'],
                    'image_url' => $imgWebUrl,
                    'is_favorite' => (bool)$row['is_favorite']
                ];
            }

            // Build array for all 7 calendar days in range (newest first)
            $germanWeekdays = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
            $daysList = [];

            for ($d = $endDayOffset; $d <= $startDayOffset; $d++) {
                $dayTs = strtotime("-{$d} days", $todayTimestamp);
                $dateKey = date('Y-m-d', $dayTs);
                $dayOfWeekNum = (int)date('w', $dayTs);
                $weekdayName = $germanWeekdays[$dayOfWeekNum];
                $dateFormatted = date('d.m.Y', $dayTs);

                $dayMeals = $mealsByDate[$dateKey] ?? [];
                $dayTotalCalories = 0;
                foreach ($dayMeals as $m) {
                    $dayTotalCalories += $m['calories'];
                }

                $daysList[] = [
                    'date_key' => $dateKey,
                    'date_formatted' => $dateFormatted,
                    'weekday_name' => $weekdayName,
                    'total_calories' => $dayTotalCalories,
                    'meals' => $dayMeals
                ];
            }

            // Check if there are older records prior to startDateStr
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM meals
                WHERE account_id = :account_id
                  AND consumed_at < :start_date
            ");
            $checkStmt->execute([
                'account_id' => $_SESSION['user_id'],
                'start_date' => $startDateStr
            ]);
            $olderCount = (int)$checkStmt->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'page' => $page,
                'days' => $daysList,
                'has_more' => ($olderCount > 0)
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Failed to fetch history: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Laden der Historie.',
                'error_detail' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Handler: Statistiken abrufen (Tageszeit & Wochentag)
    if ($action === 'get_stats') {
        ensure_meals_table_exists($pdo);

        $range = $_REQUEST['range'] ?? '30';
        if (!in_array($range, ['30', '90', 'all'], true)) {
            $range = '30';
        }

        try {
            $params = ['account_id' => $_SESSION['user_id']];
            $whereSql = "WHERE account_id = :account_id";

            if ($range === '30') {
                $startTimestamp = strtotime('-29 days 00:00:00');
                $whereSql .= " AND consumed_at >= :start_date";
                $params['start_date'] = date('Y-m-d 00:00:00', $startTimestamp);
            } elseif ($range === '90') {
                $startTimestamp = strtotime('-89 days 00:00:00');
                $whereSql .= " AND consumed_at >= :start_date";
                $params['start_date'] = date('Y-m-d 00:00:00', $startTimestamp);
            }

            $stmt = $pdo->prepare("
                SELECT id, consumed_at, calories
                FROM meals
                {$whereSql}
                ORDER BY consumed_at ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Initialize Interval Data (8 slots: 00-03, 03-06, 06-09, 09-12, 12-15, 15-18, 18-21, 21-24)
            $intervalLabels = [
                0 => ['label' => '00-03', 'full_label' => '00:00 – 03:00 Uhr'],
                1 => ['label' => '03-06', 'full_label' => '03:00 – 06:00 Uhr'],
                2 => ['label' => '06-09', 'full_label' => '06:00 – 09:00 Uhr'],
                3 => ['label' => '09-12', 'full_label' => '09:00 – 12:00 Uhr'],
                4 => ['label' => '12-15', 'full_label' => '12:00 – 15:00 Uhr'],
                5 => ['label' => '15-18', 'full_label' => '15:00 – 18:00 Uhr'],
                6 => ['label' => '18-21', 'full_label' => '18:00 – 21:00 Uhr'],
                7 => ['label' => '21-24', 'full_label' => '21:00 – 24:00 Uhr'],
            ];

            $intervalStats = [];
            for ($i = 0; $i < 8; $i++) {
                $intervalStats[$i] = [
                    'slot' => $i,
                    'label' => $intervalLabels[$i]['label'],
                    'full_label' => $intervalLabels[$i]['full_label'],
                    'total_calories' => 0,
                    'meal_count' => 0,
                    'avg_calories' => 0
                ];
            }

            // Initialize Weekdays (1 = Montag ... 7 = Sonntag)
            $weekdayNames = [
                1 => ['short' => 'Mo', 'full' => 'Montag'],
                2 => ['short' => 'Di', 'full' => 'Dienstag'],
                3 => ['short' => 'Mi', 'full' => 'Mittwoch'],
                4 => ['short' => 'Do', 'full' => 'Donnerstag'],
                5 => ['short' => 'Fr', 'full' => 'Freitag'],
                6 => ['short' => 'Sa', 'full' => 'Samstag'],
                7 => ['short' => 'So', 'full' => 'Sonntag'],
            ];

            $weekdayStats = [];
            $weekdayDates = [];
            for ($w = 1; $w <= 7; $w++) {
                $weekdayStats[$w] = [
                    'weekday' => $w,
                    'short_label' => $weekdayNames[$w]['short'],
                    'full_label' => $weekdayNames[$w]['full'],
                    'total_calories' => 0,
                    'meal_count' => 0,
                    'avg_calories' => 0
                ];
                $weekdayDates[$w] = [];
            }

            $totalCalories = 0;
            $totalMeals = count($rows);
            $distinctDays = [];

            foreach ($rows as $row) {
                $cal = (int)$row['calories'];
                $ts = strtotime($row['consumed_at']);
                $dateKey = date('Y-m-d', $ts);
                $hour = (int)date('G', $ts);
                $isoDow = (int)date('N', $ts);

                $totalCalories += $cal;
                $distinctDays[$dateKey] = true;

                $slot = (int)floor($hour / 3);
                if ($slot >= 0 && $slot <= 7) {
                    $intervalStats[$slot]['total_calories'] += $cal;
                    $intervalStats[$slot]['meal_count']++;
                }

                if ($isoDow >= 1 && $isoDow <= 7) {
                    $weekdayStats[$isoDow]['total_calories'] += $cal;
                    $weekdayStats[$isoDow]['meal_count']++;
                    $weekdayDates[$isoDow][$dateKey] = true;
                }
            }

            $activeDaysCount = count($distinctDays);

            // Compute averages for intervals
            $maxIntervalAvg = 0;
            $peakSlotIndex = null;
            foreach ($intervalStats as $i => &$slotData) {
                if ($activeDaysCount > 0) {
                    $slotData['avg_calories'] = (int)round($slotData['total_calories'] / $activeDaysCount);
                } else {
                    $slotData['avg_calories'] = 0;
                }
                if ($slotData['avg_calories'] > $maxIntervalAvg) {
                    $maxIntervalAvg = $slotData['avg_calories'];
                    $peakSlotIndex = $i;
                }
            }
            unset($slotData);

            // Compute averages for weekdays
            $maxWeekdayAvg = 0;
            $peakWeekdayNum = null;
            foreach ($weekdayStats as $w => &$wData) {
                $daysForThisWeekday = count($weekdayDates[$w]);
                if ($daysForThisWeekday > 0) {
                    $wData['avg_calories'] = (int)round($wData['total_calories'] / $daysForThisWeekday);
                } else {
                    $wData['avg_calories'] = 0;
                }
                if ($wData['avg_calories'] > $maxWeekdayAvg) {
                    $maxWeekdayAvg = $wData['avg_calories'];
                    $peakWeekdayNum = $w;
                }
            }
            unset($wData);

            $avgDailyCalories = $activeDaysCount > 0 ? (int)round($totalCalories / $activeDaysCount) : 0;

            $peakIntervalLabel = ($peakSlotIndex !== null && $maxIntervalAvg > 0)
                ? $intervalStats[$peakSlotIndex]['full_label']
                : '–';

            $peakWeekdayLabel = ($peakWeekdayNum !== null && $maxWeekdayAvg > 0)
                ? $weekdayStats[$peakWeekdayNum]['full_label']
                : '–';

            echo json_encode([
                'status' => 'success',
                'range' => $range,
                'is_empty' => ($totalMeals === 0),
                'kpi' => [
                    'avg_daily_calories' => $avgDailyCalories,
                    'peak_interval' => $peakIntervalLabel,
                    'peak_interval_avg' => $maxIntervalAvg,
                    'peak_weekday' => $peakWeekdayLabel,
                    'peak_weekday_avg' => $maxWeekdayAvg,
                    'total_meals' => $totalMeals,
                    'total_calories' => $totalCalories,
                    'active_days' => $activeDaysCount
                ],
                'intervals' => array_values($intervalStats),
                'weekdays' => array_values($weekdayStats)
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Failed to fetch statistics: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Laden der Statistiken.',
                'error_detail' => $e->getMessage()
            ]);
            exit;
        }
    }

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

    // Handler 2: Mahlzeit in Datenbank speichern (User klickt "Speichern 💾")
    if ($action === 'save_meal') {
        $tableOk = ensure_meals_table_exists($pdo);
        if (!$tableOk) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Vorbereiten der Datenbank-Tabelle. Bitte erstelle die Tabelle "meals" in der PostgreSQL-Datenbank.',
                'error_detail' => 'Tabelle "meals" existiert nicht und konnte nicht automatisch erstellt werden (fehlende CREATE-Rechte).'
            ]);
            exit;
        }

        $consumedAtInput = $_POST['consumed_at'] ?? '';
        if (!empty($consumedAtInput)) {
            $timestamp = strtotime($consumedAtInput);
            $consumedAt = ($timestamp !== false) ? date('Y-m-d H:i:sP', $timestamp) : date('Y-m-d H:i:sP');
        } else {
            $consumedAt = date('Y-m-d H:i:sP');
        }

        $title = trim($_POST['title'] ?? 'Mahlzeit');
        if (empty($title)) {
            $title = 'Mahlzeit';
        }
        $photoPath = $_POST['photo_path'] ?? '';
        $imageFilename = basename($photoPath);
        
        $rawAiModel = trim($_POST['ai_model'] ?? '');
        $aiModel = ($rawAiModel !== '' && $rawAiModel !== 'null' && $rawAiModel !== 'undefined') ? $rawAiModel : null;

        $aiAttempts = max(1, (int)($_POST['ai_attempts'] ?? 1));
        $processingTimeMs = max(0, (int)($_POST['processing_time_ms'] ?? 0));
        $ingredientsInput = $_POST['ingredients'] ?? '';
        $ingredientsStr = is_array($ingredientsInput) ? implode(', ', array_filter(array_map('trim', $ingredientsInput))) : trim((string)$ingredientsInput);
        $healthRating = $_POST['health_rating'] ?? '';
        $calories = max(0, (int)($_POST['calories'] ?? 0));
        $portion = max(1, min(500, (int)($_POST['portion'] ?? 100)));

        try {
            $stmt = $pdo->prepare("
                INSERT INTO meals (account_id, consumed_at, title, image_filename, ai_model, ai_attempts, processing_time_ms, ingredients, health_rating, calories, portion)
                VALUES (:account_id, :consumed_at, :title, :image_filename, :ai_model, :ai_attempts, :processing_time_ms, :ingredients, :health_rating, :calories, :portion)
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
                'calories' => $calories,
                'portion' => $portion
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
                'message' => 'Fehler beim Speichern der Mahlzeit in der Datenbank.',
                'error_detail' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Handler: Mahlzeit löschen
    if ($action === 'delete_meal') {
        ensure_meals_table_exists($pdo);
        ensure_favorites_table_exists($pdo);

        $mealId = (int)($_POST['meal_id'] ?? 0);
        if ($mealId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Ungültige Mahlzeiten-ID.']);
            exit;
        }

        try {
            $stmtFetch = $pdo->prepare("SELECT image_filename FROM meals WHERE id = :id AND account_id = :account_id");
            $stmtFetch->execute([
                'id' => $mealId,
                'account_id' => $_SESSION['user_id']
            ]);
            $meal = $stmtFetch->fetch();

            if (!$meal) {
                echo json_encode(['status' => 'error', 'message' => 'Mahlzeit nicht gefunden oder keine Berechtigung.']);
                exit;
            }

            $imageFilename = $meal['image_filename'] ?? '';

            $stmtDelete = $pdo->prepare("DELETE FROM meals WHERE id = :id AND account_id = :account_id");
            $stmtDelete->execute([
                'id' => $mealId,
                'account_id' => $_SESSION['user_id']
            ]);

            if (!empty($imageFilename)) {
                $stmtCheckImg = $pdo->prepare("
                    SELECT (
                        (SELECT COUNT(*) FROM meals WHERE image_filename = :img1) +
                        (SELECT COUNT(*) FROM favorites WHERE image_filename = :img2)
                    ) AS ref_count
                ");
                $stmtCheckImg->execute([
                    'img1' => $imageFilename,
                    'img2' => $imageFilename
                ]);
                $refRow = $stmtCheckImg->fetch();
                if (empty($refRow['ref_count']) || (int)$refRow['ref_count'] === 0) {
                    $imgPath = __DIR__ . '/uploads/photos/' . $imageFilename;
                    if (file_exists($imgPath)) {
                        @unlink($imgPath);
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Mahlzeit erfolgreich gelöscht.'
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Failed to delete meal: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Löschen der Mahlzeit.',
                'error_detail' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Handler: Favoriten umschalten (Toggle)
    if ($action === 'toggle_favorite') {
        // Server-Schutz: 1 Sekunde Verzögerung
        sleep(1);

        ensure_meals_table_exists($pdo);
        ensure_favorites_table_exists($pdo);

        $mealId = (int)($_POST['meal_id'] ?? 0);
        if ($mealId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Ungültige Mahlzeiten-ID.']);
            exit;
        }

        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM favorites WHERE account_id = :account_id AND meal_id = :meal_id");
            $stmtCheck->execute([
                'account_id' => $_SESSION['user_id'],
                'meal_id' => $mealId
            ]);
            $favRow = $stmtCheck->fetch();

            if ($favRow) {
                $stmtDelFav = $pdo->prepare("DELETE FROM favorites WHERE account_id = :account_id AND meal_id = :meal_id");
                $stmtDelFav->execute([
                    'account_id' => $_SESSION['user_id'],
                    'meal_id' => $mealId
                ]);

                echo json_encode([
                    'status' => 'success',
                    'is_favorite' => false,
                    'message' => 'Mahlzeit aus Favorieten entfernt.'
                ]);
                exit;
            } else {
                $stmtMeal = $pdo->prepare("SELECT title, image_filename, ingredients, health_rating, calories, portion, consumed_at FROM meals WHERE id = :id AND account_id = :account_id");
                $stmtMeal->execute([
                    'id' => $mealId,
                    'account_id' => $_SESSION['user_id']
                ]);
                $mealData = $stmtMeal->fetch();

                if (!$mealData) {
                    echo json_encode(['status' => 'error', 'message' => 'Mahlzeit nicht gefunden.']);
                    exit;
                }

                $mealPortion = max(1, (int)($mealData['portion'] ?? 100));
                $mealCalories = (int)($mealData['calories'] ?? 0);
                $baseCalories = (int)round($mealCalories * (100 / $mealPortion));

                $stmtInsFav = $pdo->prepare("
                    INSERT INTO favorites (account_id, meal_id, title, image_filename, ingredients, health_rating, calories, portion, consumed_at, last_used_at)
                    VALUES (:account_id, :meal_id, :title, :image_filename, :ingredients, :health_rating, :calories, :portion, :consumed_at, CURRENT_TIMESTAMP)
                    ON CONFLICT (account_id, meal_id) DO UPDATE SET
                        last_used_at = CURRENT_TIMESTAMP,
                        portion = EXCLUDED.portion,
                        calories = EXCLUDED.calories
                ");
                $stmtInsFav->execute([
                    'account_id' => $_SESSION['user_id'],
                    'meal_id' => $mealId,
                    'title' => $mealData['title'] ?? 'Mahlzeit',
                    'image_filename' => $mealData['image_filename'] ?? '',
                    'ingredients' => $mealData['ingredients'] ?? '',
                    'health_rating' => $mealData['health_rating'] ?? '',
                    'calories' => $baseCalories,
                    'portion' => 100,
                    'consumed_at' => $mealData['consumed_at'] ?? date('Y-m-d H:i:sP')
                ]);

                echo json_encode([
                    'status' => 'success',
                    'is_favorite' => true,
                    'message' => 'Mahlzeit zu Favorieten hinzugefügt.'
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log("Failed to toggle favorite: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Fehler beim Speichern des Favoriten.',
                'error_detail' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Handler: Favorit als verwendet markieren
    if ($action === 'touch_favorite') {
        ensure_favorites_table_exists($pdo);

        $favId = (int)($_POST['favorite_id'] ?? ($_POST['id'] ?? 0));
        $mealId = (int)($_POST['meal_id'] ?? 0);

        if ($favId <= 0 && $mealId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Ungültige ID.']);
            exit;
        }

        try {
            if ($favId > 0) {
                $stmt = $pdo->prepare("UPDATE favorites SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id AND account_id = :account_id");
                $stmt->execute(['id' => $favId, 'account_id' => $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE favorites SET last_used_at = CURRENT_TIMESTAMP WHERE meal_id = :meal_id AND account_id = :account_id");
                $stmt->execute(['meal_id' => $mealId, 'account_id' => $_SESSION['user_id']]);
            }

            echo json_encode(['status' => 'success']);
            exit;
        } catch (Exception $e) {
            error_log("Failed to touch favorite: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // Handler: Favorit löschen
    if ($action === 'delete_favorite') {
        ensure_favorites_table_exists($pdo);

        $favId = (int)($_POST['favorite_id'] ?? ($_POST['id'] ?? 0));
        $mealId = (int)($_POST['meal_id'] ?? 0);

        if ($favId <= 0 && $mealId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Ungültige Favoriten-ID.']);
            exit;
        }

        try {
            $imageFilename = '';
            if ($favId > 0) {
                $stmtFetch = $pdo->prepare("SELECT image_filename FROM favorites WHERE id = :id AND account_id = :account_id");
                $stmtFetch->execute(['id' => $favId, 'account_id' => $_SESSION['user_id']]);
                $favRow = $stmtFetch->fetch();
                $imageFilename = $favRow['image_filename'] ?? '';

                $stmt = $pdo->prepare("DELETE FROM favorites WHERE id = :id AND account_id = :account_id");
                $stmt->execute(['id' => $favId, 'account_id' => $_SESSION['user_id']]);
            } else {
                $stmtFetch = $pdo->prepare("SELECT image_filename FROM favorites WHERE meal_id = :meal_id AND account_id = :account_id");
                $stmtFetch->execute(['meal_id' => $mealId, 'account_id' => $_SESSION['user_id']]);
                $favRow = $stmtFetch->fetch();
                $imageFilename = $favRow['image_filename'] ?? '';

                $stmt = $pdo->prepare("DELETE FROM favorites WHERE meal_id = :meal_id AND account_id = :account_id");
                $stmt->execute(['meal_id' => $mealId, 'account_id' => $_SESSION['user_id']]);
            }

            if (!empty($imageFilename)) {
                $stmtCheckImg = $pdo->prepare("
                    SELECT (
                        (SELECT COUNT(*) FROM meals WHERE image_filename = :img1) +
                        (SELECT COUNT(*) FROM favorites WHERE image_filename = :img2)
                    ) AS ref_count
                ");
                $stmtCheckImg->execute([
                    'img1' => $imageFilename,
                    'img2' => $imageFilename
                ]);
                $refRow = $stmtCheckImg->fetch();
                if (empty($refRow['ref_count']) || (int)$refRow['ref_count'] === 0) {
                    $imgPath = __DIR__ . '/uploads/photos/' . $imageFilename;
                    if (file_exists($imgPath)) {
                        @unlink($imgPath);
                    }
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Favorit aus Favoriten entfernt.']);
            exit;
        } catch (Exception $e) {
            error_log("Failed to delete favorite: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Fehler beim Löschen des Favoriten.']);
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
if (function_exists('exec') && (is_dir(__DIR__ . '/../.git') || is_dir(__DIR__ . '/.git'))) {
    $gitTimestamp = @exec('git -C ' . escapeshellarg(__DIR__) . ' log -1 --format="%ct" 2>/dev/null');
    if (is_numeric($gitTimestamp) && (int)$gitTimestamp > 0) {
        $buildTimestamp = (int)$gitTimestamp;
    }
}
if (!$buildTimestamp) {
    $filesToCheck = [
        __DIR__ . '/index.php',
        __DIR__ . '/auth_helper.php',
        __DIR__ . '/prompts.php',
        __DIR__ . '/js/app.js',
        __DIR__ . '/css/style.css'
    ];
    $mtimes = [];
    foreach ($filesToCheck as $f) {
        if (file_exists($f)) {
            $mtimes[] = filemtime($f);
        }
    }
    $buildTimestamp = !empty($mtimes) ? max($mtimes) : time();
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
                <img src="apple-touch-icon.png" alt="fressi" class="logo-icon">
                <span>fressi</span>
            </a>

            <div class="nav-actions">
                <div class="user-badge" title="Eingeloggt als <?php echo htmlspecialchars($user['username']); ?>">
                    <span>Hallo, <strong><?php echo htmlspecialchars(ucfirst($user['username'])); ?></strong></span>
                </div>
                <div class="hamburger-menu-container">
                    <button id="hamburger-btn" class="hamburger-btn" aria-label="Menü öffnen" aria-expanded="false" title="Menü">
                        <span class="hamburger-icon">☰</span>
                    </button>
                    <div id="hamburger-dropdown" class="hamburger-dropdown" style="display: none;">
                        <a href="#" id="menu-item-history" class="dropdown-item">
                            <span class="dropdown-icon">📜</span>
                            <span>History</span>
                        </a>
                        <a href="#" id="menu-item-stats" class="dropdown-item">
                            <span class="dropdown-icon">📊</span>
                            <span>Statistiken</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" id="logout-btn" class="dropdown-item dropdown-item-logout">
                            <span class="dropdown-icon">🚪</span>
                            <span>Abmelden</span>
                        </a>
                    </div>
                </div>
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
                        <button type="button" id="btn-select-favorite" class="btn-secondary select-favorite-btn">
                            ⭐ Aus Favorieten wählen
                        </button>
                    </div>
                </div>

                <!-- Status Feedback Message -->
                <div id="status-box" class="status-box" style="display: none;"></div>

                <!-- Build Info Footer -->
                <footer class="build-info-footer">
                    <small>Letzter Build: <?php echo htmlspecialchars($buildDateFormatted); ?></small>
                </footer>
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
                        <label class="form-label">📅 Datum &amp; Uhrzeit der Mahlzeit</label>
                        <div class="form-row">
                            <div class="col-half">
                                <input type="date" id="field-date" class="form-control" required>
                            </div>
                            <div class="col-half">
                                <select id="field-time" class="form-control" required>
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <?php for ($m = 0; $m < 60; $m += 15): ?>
                                            <?php $t = sprintf('%02d:%02d', $h, $m); ?>
                                            <option value="<?= $t ?>"><?= $t ?> Uhr</option>
                                        <?php endfor; ?>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
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
                                <option value="1">1 %</option>
                                <option value="2">2 %</option>
                                <option value="5">5 %</option>
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
                                <option value="125">125 %</option>
                                <option value="150">150 %</option>
                                <option value="175">175 %</option>
                                <option value="200">200 %</option>
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

            <!-- History Card (Hidden by default) -->
            <div id="history-card" class="photo-card history-card" style="display: none;">
                <button id="btn-close-history" class="modal-close-btn" title="Zurück zur Kamera" aria-label="Schließen">&times;</button>
                <div class="card-header history-header">
                    <h1 class="card-title">Mahlzeiten-<span class="accent-text">Historie</span></h1>
                </div>

                <div id="history-list-container" class="history-list-container">
                    <!-- Dynamic day blocks rendered here via JS -->
                </div>

                <div id="history-status" class="status-box" style="display: none;"></div>

                <div class="history-actions">
                    <button id="btn-load-more-history" class="btn-primary load-more-btn" style="display: none;">
                        Mehr laden ⏳
                    </button>
                </div>
            </div>

            <!-- Statistics Card (Hidden by default) -->
            <div id="stats-card" class="photo-card stats-card" style="display: none;">
                <button id="btn-close-stats" class="modal-close-btn" title="Zurück zur Kamera" aria-label="Schließen">&times;</button>
                <div class="card-header stats-header">
                    <h1 class="card-title">Kalorien-<span class="accent-text">Statistik</span></h1>
                </div>

                <!-- Range Selector Pills -->
                <div class="stats-filter-bar" role="group" aria-label="Zeitraum auswählen">
                    <button type="button" class="stats-filter-btn active" data-range="30">30 Tage</button>
                    <button type="button" class="stats-filter-btn" data-range="90">90 Tage</button>
                    <button type="button" class="stats-filter-btn" data-range="all">Gesamt</button>
                </div>

                <div id="stats-status" class="status-box" style="display: none;"></div>

                <div id="stats-content-area" class="stats-content-area">
                    <!-- KPI Summary Grid -->
                    <div id="stats-kpi-container" class="stats-kpi-grid"></div>

                    <!-- Chart 1: Time of Day (3-hour intervals) -->
                    <div class="stats-chart-section">
                        <div class="stats-chart-header">
                            <h2 class="stats-chart-title">🕒 Nach Tageszeit</h2>
                            <span class="stats-chart-subtitle">Ø kcal im 3-Stunden-Intervall</span>
                        </div>
                        <div id="chart-intervals-container" class="css-chart-container"></div>
                    </div>

                    <!-- Chart 2: Weekday -->
                    <div class="stats-chart-section">
                        <div class="stats-chart-header">
                            <h2 class="stats-chart-title">📅 Nach Wochentag</h2>
                            <span class="stats-chart-subtitle">Ø kcal pro Wochentag</span>
                        </div>
                        <div id="chart-weekdays-container" class="css-chart-container"></div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Meal Detail Modal -->
    <div id="meal-detail-modal" class="modal-overlay" style="display: none;">
        <div class="modal-card">
            <button id="btn-close-modal" class="modal-close-btn" title="Schließen">&times;</button>
            <div class="modal-header">
                <h2 id="modal-meal-title" class="modal-title">Mahlzeit Details</h2>
                <div id="modal-meal-datetime" class="modal-subtitle">Datum & Uhrzeit</div>
            </div>
            <div class="modal-body">
                <div id="modal-image-container" class="modal-image-container" style="display: none;">
                    <img id="modal-meal-image" src="" alt="Foto der Mahlzeit" class="modal-meal-img">
                </div>
                <div class="modal-section">
                    <h3>💚 Wertigkeit & Wohlbefinden</h3>
                    <div id="modal-meal-health" class="health-rating-box modal-health-box">-</div>
                </div>
                <div class="modal-section">
                    <h3>🍽️ Verzehrmenge</h3>
                    <div id="modal-meal-portion" class="modal-portion-box">100 %</div>
                </div>
                <div class="modal-section">
                    <h3>🔥 Kalorien</h3>
                    <div id="modal-meal-calories" class="modal-calories-box">0 kcal</div>
                </div>
                <div class="modal-section">
                    <h3>🥗 Zutaten & Bestandteile</h3>
                    <div id="modal-ingredients-chips" class="ingredients-chips"></div>
                </div>
            </div>
            <div class="modal-footer-actions">
                <button type="button" id="btn-modal-favorite" class="btn-secondary btn-modal-fav">
                    <span id="modal-fav-icon">☆</span> Favorieten
                </button>
                <button type="button" id="btn-modal-delete" class="btn-danger btn-modal-delete">
                    🗑️ Löschen
                </button>
            </div>
        </div>
    </div>

    <!-- Favorites Selection Modal -->
    <div id="favorites-modal" class="modal-overlay" style="display: none;">
        <div class="modal-card favorites-modal-card">
            <button id="btn-close-favorites-modal" class="modal-close-btn" title="Schließen">&times;</button>
            <div class="modal-header">
                <h2 class="modal-title">⭐ Meine <span class="accent-text">Favorieten</span></h2>
                <div class="modal-subtitle">Wähle eine gespeicherte Mahlzeit als Vorlage</div>
            </div>
            <div class="modal-body">
                <div id="favorites-list-container" class="favorites-list-container">
                    <!-- Dynamic compact favorite list items -->
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container"></div>

    <!-- JavaScript -->
    <script src="js/app.js?v=<?php echo filemtime(__DIR__ . '/js/app.js'); ?>"></script>
</body>
</html>
