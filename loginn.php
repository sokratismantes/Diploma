<?php
session_start();

/**
 * CONFIG
 */
const GOOGLE_CLIENT_ID = '838912664090-vvj7itsf3b813j27c8l7ea4asj6093na.apps.googleusercontent.com';
const LAB_MODE = true;
const AI_RATE_LIMIT_MAX = 50;
const AI_RATE_LIMIT_WINDOW = 3600;

require_once __DIR__ . '/gemini_instructor_prompt.php';
require_once __DIR__ . '/diploma_logger.php';

/**
 * HELPERS
 */
function jsonReadFile(string $path, $default = []) {
    if (!file_exists($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false) return $default;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $default;
}

function jsonWriteFile(string $path, $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $tmp = $path . '.tmp';
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($ok === false) return false;
    return @rename($tmp, $path);
}

function getGeminiApiKey(): string {
    $envKey = trim(getenv('GEMINI_API_KEY') ?: '');
    if ($envKey !== '') return $envKey;

    $secretFile = __DIR__ . '/config.secret.php';
    if (file_exists($secretFile)) {
        $secret = require $secretFile;
        if (is_array($secret) && !empty($secret['GEMINI_API_KEY'])) {
            return trim($secret['GEMINI_API_KEY']);
        }
    }

    return '';
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['sub']);
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

function getParticipantId(): string {
    return trim((string)($_SESSION['participant_id'] ?? ''));
}

function checkAiRateLimit(string $userId): array {
    $file = __DIR__ . '/rate_limits.json';
    $store = jsonReadFile($file, []);

    $now = time();
    if (!isset($store[$userId])) {
        $store[$userId] = [
            'window_start' => $now,
            'count' => 0
        ];
    }

    $winStart = (int)($store[$userId]['window_start'] ?? $now);
    $count    = (int)($store[$userId]['count'] ?? 0);

    if ($now - $winStart >= AI_RATE_LIMIT_WINDOW) {
        $winStart = $now;
        $count = 0;
    }

    $allowed = $count < AI_RATE_LIMIT_MAX;
    if ($allowed) {
        $count++;
    }

    $store[$userId] = [
        'window_start' => $winStart,
        'count' => $count
    ];

    jsonWriteFile($file, $store);

    $remaining = max(0, AI_RATE_LIMIT_MAX - $count);
    $resetIn = max(0, AI_RATE_LIMIT_WINDOW - ($now - $winStart));

    return [
        'allowed' => $allowed,
        'remaining' => $remaining,
        'reset_in' => $resetIn,
        'limit' => AI_RATE_LIMIT_MAX
    ];
}

function verifyGoogleIdToken(string $idToken): array {
    if ($idToken === '') return ['ok' => false, 'error' => 'Empty token'];

    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15
    ]);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'error' => 'cURL error: ' . $err];
    }

    $json = json_decode($res, true);
    if ($http < 200 || $http >= 300 || !is_array($json)) {
        return ['ok' => false, 'error' => 'Token verification failed', 'details' => $json ?? $res];
    }

    $aud = $json['aud'] ?? '';
    if ($aud !== GOOGLE_CLIENT_ID) {
        return ['ok' => false, 'error' => 'Invalid audience'];
    }

    $sub = $json['sub'] ?? '';
    if ($sub === '') {
        return ['ok' => false, 'error' => 'No sub in token'];
    }

    return [
        'ok' => true,
        'payload' => $json
    ];
}

/**
 * CHAT HISTORY HELPERS
 * Αποθηκεύουμε νέο ιστορικό ανά login session του χρήστη.
 */
function getCurrentChatSessionId(): string {
    if (empty($_SESSION['chat_session_id'])) {
        $_SESSION['chat_session_id'] = bin2hex(random_bytes(8));
    }
    return $_SESSION['chat_session_id'];
}

function getUserHistoryFile(string $userId): string {
    $safeUserId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $userId);
    $sessionId  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', getCurrentChatSessionId());

    $participantId = getParticipantId();
    $safeParticipantId = $participantId !== ''
        ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $participantId)
        : 'unknown_participant';

    return __DIR__ . '/chat_history/' . $safeParticipantId . '_' . $safeUserId . '_' . $sessionId . '.json';
}

function readUserHistory(string $userId): array {
    $file = getUserHistoryFile($userId);
    $history = jsonReadFile($file, []);
    return is_array($history) ? $history : [];
}

function writeUserHistory(string $userId, array $history): bool {
    $file = getUserHistoryFile($userId);
    return jsonWriteFile($file, $history);
}

function appendUserMessage(string $userId, string $role, string $text): bool {
    $history = readUserHistory($userId);

    $history[] = [
        'role' => $role,
        'text' => $text,
        'timestamp' => date('Y-m-d H:i:s'),
        'participant_id' => getParticipantId(),
        'google_sub' => $userId,
        'chat_session_id' => getCurrentChatSessionId()
    ];

    $history = array_slice($history, -30);

    return writeUserHistory($userId, $history);
}

function normalizeGeminiText(string $text, int $maxChars = 12000): string {
    $text = trim($text);
    if ($text === '') return '';

    if (mb_strlen($text, 'UTF-8') > $maxChars) {
        $text = mb_substr($text, -$maxChars, null, 'UTF-8');
    }

    return $text;
}

function normalizeGeminiTurnOrder(array $contents): array {
    $normalized = [];

    foreach ($contents as $item) {
        $role = $item['role'] ?? '';
        $parts = $item['parts'] ?? [];
        $text = '';

        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $text .= (string)$part['text'];
                }
            }
        }

        $text = normalizeGeminiText($text);

        if ($text === '') continue;
        if ($role !== 'user' && $role !== 'model') continue;

        $lastIndex = count($normalized) - 1;

        if ($lastIndex >= 0 && $normalized[$lastIndex]['role'] === $role) {
            $normalized[$lastIndex]['parts'][0]['text'] .= "\n\n" . $text;
        } else {
            $normalized[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $text]
                ]
            ];
        }
    }

    while (!empty($normalized) && $normalized[0]['role'] !== 'user') {
        array_shift($normalized);
    }

    return $normalized;
}

function buildGeminiContentsFromHistory(array $history, string $newPrompt): array {
    $contents = [];

    foreach ($history as $item) {
        $role = $item['role'] ?? '';
        $text = normalizeGeminiText((string)($item['text'] ?? ''), 5000);

        if ($text === '') continue;
        if ($role !== 'user' && $role !== 'model') continue;

        $contents[] = [
            'role' => $role,
            'parts' => [
                ['text' => $text]
            ]
        ];
    }

    $finalUserPrompt =
        GEMINI_INSTRUCTOR_PROMPT .
        "\n\n---\n" .
        "Τρέχον μήνυμα φοιτητή:\n" .
        normalizeGeminiText($newPrompt, 12000);

    $contents[] = [
        'role' => 'user',
        'parts' => [
            ['text' => $finalUserPrompt]
        ]
    ];

    return normalizeGeminiTurnOrder($contents);
}

function extractGeminiText(array $json): string {
    $text = '';
    $parts = $json['candidates'][0]['content']['parts'] ?? [];

    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= (string)$part['text'];
            }
        }
    }

    return trim($text);
}

function getGeminiFinishReason(array $json): string {
    return (string)($json['candidates'][0]['finishReason'] ?? '');
}

function shouldRetryGemini(int $httpCode, int $curlErrno): bool {
    if ($curlErrno !== 0) return true;
    return in_array($httpCode, [408, 429, 500, 502, 503, 504], true);
}

function callGeminiApi(string $url, string $apiKey, array $payload, int $maxAttempts = 3): array {
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($encodedPayload === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'json' => null,
            'raw' => '',
            'curl_errno' => 0,
            'curl_error' => 'JSON encode failed: ' . json_last_error_msg()
        ];
    }

    $last = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'x-goog-api-key: ' . $apiKey
            ],
            CURLOPT_POSTFIELDS     => $encodedPayload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $err      = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $raw = is_string($response) ? $response : '';
        $json = json_decode($raw, true);

        $last = [
            'ok' => ($errno === 0 && $httpCode >= 200 && $httpCode < 300 && is_array($json)),
            'http_code' => $httpCode,
            'json' => is_array($json) ? $json : null,
            'raw' => $raw,
            'curl_errno' => $errno,
            'curl_error' => $err,
            'attempt' => $attempt
        ];

        if ($last['ok']) return $last;

        if ($attempt < $maxAttempts && shouldRetryGemini($httpCode, $errno)) {
            usleep(250000 * $attempt);
            continue;
        }

        break;
    }

    return $last ?? [
        'ok' => false,
        'http_code' => 0,
        'json' => null,
        'raw' => '',
        'curl_errno' => 0,
        'curl_error' => 'Unknown Gemini call failure',
        'attempt' => 0
    ];
}

// ==================== API HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true) ?? [];
    $action = $data['action'] ?? '';

    $loggerActions = [
        'save_objective_log',
        'save_submission',
        'get_objective_log',
        'get_objective_summary'
    ];

    if (in_array($action, $loggerActions, true)) {
        diplomaLoggerHandleRequest();
    }

    if ($action === 'google_login') {
        $credential = trim($data['credential'] ?? '');
        $verify = verifyGoogleIdToken($credential);

        if (!$verify['ok']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Αποτυχία σύνδεσης με Google.',
                'details' => $verify['error'] ?? null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $p = $verify['payload'];

        $_SESSION['user'] = [
            'sub' => $p['sub'] ?? '',
            'email' => $p['email'] ?? '',
            'name' => $p['name'] ?? ($p['email'] ?? 'User'),
            'picture' => $p['picture'] ?? '',
            'given_name' => $p['given_name'] ?? '',
            'family_name' => $p['family_name'] ?? ''
        ];

        $_SESSION['chat_session_id'] = bin2hex(random_bytes(8));

        echo json_encode([
            'status' => 'ok',
            'user' => $_SESSION['user']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'google_logout') {
        unset($_SESSION['user']);
        unset($_SESSION['chat_session_id']);

        echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'whoami') {
        echo json_encode([
            'status' => 'ok',
            'logged_in' => isLoggedIn(),
            'user' => currentUser(),
            'participant_id' => getParticipantId()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'gemini_generate') {
        if (!isLoggedIn()) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Πρέπει να συνδεθείς με Google για να χρησιμοποιήσεις το AI.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $user = currentUser();
        $userId = $user['sub'] ?? '';

        if ($userId === '') {
            echo json_encode([
                'status' => 'error',
                'message' => 'Δεν βρέθηκε έγκυρο user id στο session.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rate = checkAiRateLimit($userId);
        if (!$rate['allowed']) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Έφτασες το όριο χρήσης AI για αυτή την ώρα.',
                'rate_limit' => $rate
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $apiKey = getGeminiApiKey();
        $prompt = normalizeGeminiText((string)($data['prompt'] ?? ''), 12000);
        $model  = trim((string)($data['model'] ?? 'gemini-2.5-flash'));
        $allowedModels = ['gemini-2.5-flash', 'gemini-2.5-pro'];

        if (!in_array($model, $allowedModels, true)) {
            $model = 'gemini-2.5-flash';
        }

        if ($apiKey === '') {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Λείπει το GEMINI_API_KEY στον server (env ή config.secret.php).'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($prompt === '') {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Λείπει prompt.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $url = "https://generativelanguage.googleapis.com/v1/models/"
            . rawurlencode($model)
            . ":generateContent";

        $history = readUserHistory($userId);

        $payload = [
            'contents' => buildGeminiContentsFromHistory($history, $prompt),
            'generationConfig' => [
                'temperature' => 0.35,
                'topP' => 0.9,
                'maxOutputTokens' => 900
            ]
        ];

        $gemini = callGeminiApi($url, $apiKey, $payload, 3);

        if (!$gemini['ok']) {
            $details = $gemini['json'] ?? $gemini['raw'];
            $message = 'Σφάλμα από Gemini API.';

            if (!empty($gemini['curl_errno'])) {
                $message = 'Σφάλμα cURL: ' . ($gemini['curl_error'] ?: 'άγνωστο cURL σφάλμα');
            } elseif (($gemini['http_code'] ?? 0) === 429) {
                $message = 'Το Gemini API επέστρεψε προσωρινό όριο χρήσης. Δοκίμασε ξανά σε λίγο.';
            } elseif (in_array(($gemini['http_code'] ?? 0), [500, 502, 503, 504], true)) {
                $message = 'Προσωρινό σφάλμα Gemini API. Δοκίμασε ξανά σε λίγο.';
            } elseif (($gemini['http_code'] ?? 0) > 0 && !is_array($gemini['json'])) {
                $message = 'Το Gemini API επέστρεψε μη έγκυρη JSON απάντηση.';
            }

            echo json_encode([
                'status'  => 'error',
                'message' => $message,
                'http_code' => $gemini['http_code'] ?? 0,
                'details' => $details,
                'rate_limit' => $rate
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $json = $gemini['json'];
        $promptFeedback = $json['promptFeedback'] ?? null;

        if (is_array($promptFeedback) && !empty($promptFeedback['blockReason'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Το Gemini μπλόκαρε το prompt λόγω πολιτικής ασφαλείας.',
                'details' => $promptFeedback,
                'rate_limit' => $rate
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $text = extractGeminiText($json);
        $finishReason = getGeminiFinishReason($json);

        if ($text === '') {
            $fallback = 'Δεν μπόρεσα να δημιουργήσω απάντηση για αυτό το μήνυμα. Δοκίμασε να το διατυπώσεις λίγο πιο συγκεκριμένα σχετικά με την άσκηση.';

            if ($finishReason !== '') {
                $fallback .= "\n\nΤεχνική ένδειξη: finishReason={$finishReason}";
            }

            $text = $fallback;
        }

        appendUserMessage($userId, 'user', $prompt);
        appendUserMessage($userId, 'model', $text);

        echo json_encode([
            'status' => 'ok',
            'text'   => $text,
            'finish_reason' => $finishReason,
            'rate_limit' => $rate,
            'participant_id' => getParticipantId()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save_logs') {
        $logFile  = __DIR__ . '/logs.json';
        $existing = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        if (!is_array($existing)) $existing = [];

        foreach (['api_key', 'authorization', 'key'] as $sensitive) {
            if (isset($data[$sensitive])) $data[$sensitive] = '[REDACTED]';
        }

        $user = currentUser();

        $existing[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $user ? [
                'sub' => $user['sub'] ?? '',
                'email' => $user['email'] ?? '',
                'name' => $user['name'] ?? ''
            ] : null,
            'participant_id' => getParticipantId(),
            'data'      => $data
        ];

        file_put_contents(
            $logFile,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'status'  => 'error',
        'message' => 'Unknown action.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Python Editor + AI</title>

  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min/vs/loader.js"></script>
  <script src="https://cdn.jsdelivr.net/pyodide/v0.29.3/full/pyodide.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --bg: #07101f;
      --bg-2: #050b16;
      --surface: rgba(13, 20, 38, 0.84);
      --surface-2: rgba(16, 24, 44, 0.96);
      --surface-soft: rgba(255,255,255,0.03);
      --text: #e8eefb;
      --text-soft: #bfd0ef;
      --text-muted: #8393b3;
      --accent: #5b8cff;
      --accent-2: #3b82f6;
      --accent-soft: rgba(91,140,255,0.14);
      --success: #22c55e;
      --danger: #ef4444;
      --warning: #f59e0b;
      --border: rgba(148,163,184,0.14);
      --border-strong: rgba(148,163,184,0.24);
      --shadow-sm: 0 10px 30px rgba(0,0,0,0.16);
      --shadow-md: 0 20px 48px rgba(0,0,0,0.24);
      --shadow-lg: 0 26px 70px rgba(0,0,0,0.30);
      --radius-sm: 12px;
      --radius-md: 18px;
      --radius-lg: 24px;
      --transition-fast: 180ms ease;
      --panel-anim: 320ms cubic-bezier(.22,.61,.36,1);
    }

    * { box-sizing: border-box; }

    html, body {
      width: 100%;
      min-height: 100%;
      margin: 0;
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
      overflow-x: hidden;
    }

    body {
      font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
    }

    html.is-fullscreen,
    body.is-fullscreen {
      width: 100vw;
      height: 100vh;
      overflow-x: hidden;
      overflow-y: auto;
    }

    *::-webkit-scrollbar { width: 10px; height: 10px; }
    *::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.18); border-radius: 999px; }
    *::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.28); }

    :fullscreen {
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
    }

    code {
      font-family: "JetBrains Mono", "Fira Code", monospace;
      color: #dbeafe;
      font-size: .95em;
    }

    .app-shell {
      max-width: 1600px;
      margin: 0 auto;
      padding: 1.2rem;
      display: flex;
      flex-direction: column;
      gap: 1rem;
      min-height: 100vh;
    }

    html.is-fullscreen .app-shell,
    body.is-fullscreen .app-shell {
      max-width: none;
      width: 100vw;
      min-height: 100vh;
      margin: 0;
      padding: .75rem;
      overflow: visible;
    }

    .card,
    .editor-wrapper,
    .result-wrapper,
    .video-card,
    .hint-card,
    .auth-shell,
    .chatbox {
      background: var(--surface);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-md);
    }

    .top-timer-group button {
      height: 34px;
      padding: 0 .8rem;
      font-size: .76rem;
      border-radius: 10px;
    }

    .top-timer-group .header-timer {
      height: 34px;
      min-width: 124px;
      padding: 0 .85rem;
    }

    .top-timer-group .header-timer-value {
      font-size: .84rem;
    }

    .top-timer-group .header-timer-label {
      font-size: .68rem;
    }

    .status-badges {
      display: flex;
      gap: .55rem;
      align-items: center;
      flex-wrap: wrap;
      margin-left: auto;
    }

    .top-timer-group {
      display: flex;
      gap: .55rem;
      align-items: center;
      flex-wrap: wrap;
    }

    .badge,
    .status-pill,
    .test-status,
    .mini-chip {
      height: 32px;
      padding: 0 .8rem;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text-soft);
      font-size: .75rem;
      white-space: nowrap;
    }

    .header-timer {
      min-width: 138px;
      height: 40px;
      padding: 0 1rem;
      border-radius: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .5rem;
      border: 1px solid rgba(91,140,255,.24);
      background:
        linear-gradient(180deg, rgba(91,140,255,.12), rgba(59,130,246,.06));
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,.05),
        0 10px 26px rgba(59,130,246,.12);
      color: var(--text);
      font-variant-numeric: tabular-nums;
    }

    .header-timer-dot {
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: var(--text-muted);
      box-shadow: 0 0 0 transparent;
      transition: background .2s ease, box-shadow .2s ease;
      flex: 0 0 auto;
    }

    .header-timer.is-running .header-timer-dot {
      background: var(--success);
      box-shadow: 0 0 12px rgba(34,197,94,.75);
      animation: pulseDot 1.2s infinite ease-in-out;
    }

    .header-timer-label {
      font-size: .72rem;
      color: var(--text-muted);
      letter-spacing: .02em;
    }

    .header-timer-value {
      font-size: .92rem;
      font-weight: 800;
      color: var(--text);
      min-width: 62px;
      text-align: center;
    }

    @keyframes pulseDot {
      0%, 100% { transform: scale(1); opacity: 1; }
      50% { transform: scale(1.18); opacity: .8; }
    }

    .main-grid {
      display: grid;
      grid-template-columns: minmax(320px, 1.2fr) 18px minmax(280px, .9fr);
      gap: 0;
      min-height: 0;
      align-items: start;
      transition: grid-template-columns var(--panel-anim);
    }

    html.is-fullscreen .main-grid,
    body.is-fullscreen .main-grid {
      min-height: calc(100vh - 1.5rem);
    }

    .main-grid.video-hidden {
      grid-template-columns: minmax(320px, 1fr) 0px 0px !important;
    }

    #panel-resizer {
      transition:
        width var(--panel-anim),
        opacity 220ms ease;
      overflow: hidden;
    }

    #video-panel {
      min-width: 0;
      transform-origin: right center;
      transition:
        opacity 260ms ease,
        transform var(--panel-anim),
        visibility 0s linear 0s;
      opacity: 1;
      transform: translateX(0) scale(1);
      visibility: visible;
      height: calc(100vh - 2.4rem);
    }

    #video-panel.stack {
      display: flex;
      flex-direction: column;
    }

    #video-panel .video-card {
      flex: 0 0 auto;
    }

    #video-panel .hint-card {
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }

    #video-panel .hint-card .result-wrapper {
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }

    #video-panel .hint-card .result-body {
      flex: 1 1 auto;
      min-height: 0;
      max-height: none;
      overflow: auto;
    }

    #ai-result-body {
      min-height: 0;
      max-height: none;
      height: 100%;
    }

    #user-panel button {
      height: 32px;
      padding: 0 .75rem;
      font-size: .74rem;
      border-radius: 10px;
    }

    .main-grid.video-hidden #video-panel {
      opacity: 0;
      transform: translateX(24px) scale(.98);
      visibility: hidden;
      pointer-events: none;
    }

    .main-grid.video-hidden #panel-resizer {
      width: 0 !important;
      opacity: 0;
      pointer-events: none;
    }

    .panel-resizer {
      position: relative;
      width: 18px;
      min-height: 100%;
      cursor: col-resize;
      user-select: none;
      touch-action: none;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255,255,255,.02);
    }

    .panel-resizer::before {
      content: "";
      width: 6px;
      height: 72px;
      border-radius: 999px;
      background:
        radial-gradient(circle, rgba(191,208,239,.85) 1.2px, transparent 1.2px);
      background-size: 6px 10px;
      background-position: center;
      opacity: .85;
      box-shadow:
        0 0 0 1px rgba(148,163,184,.18),
        0 8px 22px rgba(0,0,0,.16);
      transition: transform 180ms ease, box-shadow 180ms ease, opacity 180ms ease;
    }

    .panel-resizer::after {
      content: "";
      position: absolute;
      top: 0;
      bottom: 0;
      left: 50%;
      width: 2px;
      transform: translateX(-50%);
      background: rgba(91,140,255,.22);
      border-radius: 999px;
    }

    .panel-resizer:hover::before,
    .panel-resizer.is-dragging::before {
      opacity: 1;
      transform: scale(1.06);
      box-shadow:
        0 0 0 1px rgba(91,140,255,.28),
        0 10px 30px rgba(59,130,246,.20);
    }

    .panel-resizer:hover::after,
    .panel-resizer.is-dragging::after {
      background: rgba(91,140,255,.6);
    }

    .stack {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      min-width: 0;
    }

    .card,
    .video-card,
    .hint-card,
    .auth-shell {
      border-radius: var(--radius-lg);
      padding: 1rem;
      overflow: hidden;
    }

    .card-header-line {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: .8rem;
      flex-wrap: wrap;
      margin-bottom: .8rem;
    }

    .card-title-block {
      display: flex;
      flex-direction: column;
      gap: .2rem;
    }

    .card-title {
      display: flex;
      align-items: center;
      gap: .55rem;
      font-size: .98rem;
      font-weight: 700;
    }

    .card-title-icon {
      width: 30px;
      height: 30px;
      border-radius: 12px;
      background: rgba(91,140,255,.12);
      border: 1px solid rgba(91,140,255,.18);
      display: grid;
      place-items: center;
      flex: 0 0 auto;
    }

    .badge strong { color: var(--text); }
    .badge--dirty {
      background: rgba(245,158,11,.10);
      border-color: rgba(245,158,11,.22);
      color: #fbbf24;
    }

    .badge-dot {
      width: 7px;
      height: 7px;
      border-radius: 999px;
      background: currentColor;
      box-shadow: 0 0 10px currentColor;
    }

    .editor-wrapper {
      border-radius: 22px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01)),
        #0b1220;
      overflow: hidden;
      border: 1px solid rgba(148,163,184,.12);
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .editor-wrapper:focus-within,
    .chatbox:focus-within {
      border-color: rgba(91,140,255,.42);
      box-shadow: 0 0 0 4px rgba(91,140,255,.10), var(--shadow-md);
    }

    .editor-header,
    .editor-footer,
    .result-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .8rem .95rem;
      gap: .6rem;
      font-size: .76rem;
      color: var(--text-muted);
      background: rgba(255,255,255,.03);
    }

    .editor-header { border-bottom: 1px solid rgba(148,163,184,.08); }
    .editor-footer { border-top: 1px solid rgba(148,163,184,.08); }
    .result-header { border-bottom: 1px solid rgba(148,163,184,.08); }

    .editor-header-left,
    .editor-header-right {
      display: flex;
      align-items: center;
      gap: .5rem;
      min-width: 0;
    }

    .dot-row {
      display: flex;
      gap: 5px;
      align-items: center;
    }

    .dot-row span {
      width: 8px;
      height: 8px;
      border-radius: 999px;
    }

    .dot-row span:nth-child(1) { background: #f87171; }
    .dot-row span:nth-child(2) { background: #fbbf24; }
    .dot-row span:nth-child(3) { background: #4ade80; }

    #code-editor {
      width: 100%;
      height: 420px;
    }

    .action-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      margin-top: .85rem;
    }

    .utility-row,
    .status-bar {
      display: flex;
      gap: .5rem;
      align-items: center;
      flex-wrap: wrap;
    }

    button {
      border-radius: 12px;
      border: 1px solid transparent;
      background: transparent;
      color: var(--text);
      height: 40px;
      padding: 0 1rem;
      font-size: .82rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      cursor: pointer;
      transition: transform var(--transition-fast), box-shadow var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast), opacity var(--transition-fast);
      white-space: nowrap;
    }

    button:hover { transform: translateY(-1px); }
    button:active { transform: translateY(0); }
    button:disabled { cursor: not-allowed; opacity: .58; transform: none; }

    button.primary,
    button:not(.secondary):not(.ghost):not(.danger) {
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      color: #fff;
      box-shadow: 0 10px 26px rgba(59,130,246,.28);
    }

    button.primary:hover,
    button:not(.secondary):not(.ghost):not(.danger):hover {
      box-shadow: 0 16px 34px rgba(59,130,246,.36);
    }

    button.secondary {
      background: rgba(255,255,255,.04);
      border-color: var(--border-strong);
    }

    button.secondary:hover {
      background: rgba(255,255,255,.08);
      border-color: rgba(91,140,255,.32);
    }

    button.ghost {
      background: transparent;
      border-color: transparent;
      color: var(--text-soft);
      padding-left: .55rem;
      padding-right: .55rem;
    }

    button.ghost:hover {
      background: rgba(255,255,255,.05);
      color: var(--text);
    }

    #run-btn {
      height: 30px;
      padding: 0 .72rem;
      font-size: .74rem;
    }

    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: var(--text-muted);
      flex: 0 0 auto;
    }

    .status-dot--ok {
      background: var(--success);
      box-shadow: 0 0 12px rgba(34,197,94,.7);
    }

    .status-dot--err {
      background: #f87171;
      box-shadow: 0 0 12px rgba(248,113,113,.8);
    }

    .timer-text { color: var(--text-muted); font-size: .78rem; display: none; }
    .timer-number { font-weight: 800; color: var(--text); }

    .result-wrapper {
      border-radius: 22px;
      overflow: hidden;
      margin-top: .95rem;
    }

    .result-body {
      padding: .95rem;
      max-height: 340px;
      overflow: auto;
      font-size: .84rem;
      min-height: 180px;
    }

    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 180px;
      text-align: center;
      color: var(--text-soft);
      gap: .55rem;
      padding: 1rem;
    }

    .empty-state-icon {
      width: 56px;
      height: 56px;
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: rgba(91,140,255,.10);
      border: 1px solid rgba(91,140,255,.18);
      font-size: 1.35rem;
    }

    .empty-state-title {
      font-size: .98rem;
      font-weight: 700;
      color: var(--text);
    }

    .empty-state-text {
      max-width: 520px;
      font-size: .84rem;
      line-height: 1.6;
      color: var(--text-soft);
    }

    .test-list {
      display: flex;
      flex-direction: column;
      gap: .75rem;
    }

    .test-item {
      border-radius: 18px;
      border: 1px solid var(--border);
      padding: .85rem .9rem;
      background: rgba(255,255,255,.03);
      display: flex;
      flex-direction: column;
      gap: .5rem;
    }

    .test-title-row {
      display: flex;
      justify-content: space-between;
      gap: .6rem;
      align-items: flex-start;
      flex-wrap: wrap;
      font-size: .82rem;
    }

    .test-name {
      font-weight: 700;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .9rem;
    }

    .test-status {
      font-size: .72rem;
      font-weight: 700;
      height: 30px;
      padding: 0 .7rem;
    }

    .test-status--ok {
      border-color: rgba(34,197,94,.22);
      color: #86efac;
      background: rgba(34,197,94,.10);
    }

    .test-status--err {
      border-color: rgba(239,68,68,.22);
      color: #fca5a5;
      background: rgba(239,68,68,.10);
    }

    .test-meta {
      font-size: .76rem;
      color: var(--text-muted);
      line-height: 1.45;
    }

    .test-output {
      font-size: .77rem;
      color: var(--text);
      background: rgba(2,6,23,.72);
      border-radius: 14px;
      padding: .8rem .9rem;
      white-space: pre-wrap;
      word-break: break-word;
      border: 1px solid rgba(148,163,184,.12);
      font-family: "JetBrains Mono", monospace;
      line-height: 1.58;
      max-height: 220px;
      overflow: auto;
    }

    .hint-box-text,
    .video-card p,
    .hint-card p {
      margin: 0;
      font-size: .86rem;
      line-height: 1.55;
      color: var(--text-soft);
    }

    .toast-container {
      position: fixed;
      top: 18px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 9999;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .7rem;
      pointer-events: none;
    }

    .toast {
      min-width: 320px;
      max-width: 520px;
      text-align: center;
      padding: .92rem 1rem;
      border-radius: 16px;
      background: rgba(10, 15, 28, .94);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-lg);
      color: var(--text);
      transform: translateY(8px);
      opacity: 0;
      animation: toastIn 180ms ease forwards;
      font-size: .84rem;
      line-height: 1.5;
      pointer-events: auto;
    }

    .toast.success {
      border-color: rgba(34,197,94,.30);
      background:
        radial-gradient(circle at top left, rgba(34,197,94,.12), transparent 32%),
        rgba(10, 15, 28, .96);
      box-shadow:
        0 18px 40px rgba(0,0,0,.30),
        0 0 0 1px rgba(34,197,94,.08);
    }

    .toast.error {
      border-color: rgba(239,68,68,.25);
    }

    .toast.info {
      border-color: rgba(91,140,255,.25);
    }

    .toast::before {
      content: "";
      display: block;
      width: 42px;
      height: 4px;
      border-radius: 999px;
      background: rgba(255,255,255,.14);
      margin-bottom: .7rem;
    }

    .toast.success::before {
      background: linear-gradient(90deg, rgba(34,197,94,.95), rgba(134,239,172,.85));
    }

    .toast.error::before {
      background: linear-gradient(90deg, rgba(239,68,68,.95), rgba(252,165,165,.85));
    }

    .toast.info::before {
      background: linear-gradient(90deg, rgba(91,140,255,.95), rgba(147,197,253,.85));
    }

    @keyframes toastIn {
      to { opacity: 1; transform: translateY(0); }
    }

    .auth-shell {
      border-radius: 18px;
      padding: .85rem;
      background: rgba(255,255,255,.03);
      margin-bottom: .9rem;
    }

    .auth-box {
      display: flex;
      flex-direction: column;
      gap: .75rem;
    }

    .user-inline {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      flex-wrap: wrap;
      margin-bottom: .9rem;
    }

    .user-inline-left {
      display: flex;
      align-items: center;
      gap: .8rem;
      min-width: 0;
    }

    .user-avatar {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      border: 1px solid rgba(148,163,184,.26);
      object-fit: cover;
      background: rgba(255,255,255,.05);
      flex: 0 0 auto;
    }

    .user-meta {
      display: flex;
      flex-direction: column;
      gap: .12rem;
      min-width: 0;
    }

    .user-meta strong {
      display: block;
      font-size: .88rem;
      color: var(--text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .ai-thread {
      display: flex;
      flex-direction: column;
      gap: .75rem;
      min-height: 0;
      max-height: none;
      height: 100%;
      overflow: auto;
      padding: .1rem;
      margin-bottom: 0;
    }

    .ai-bubble {
      border-radius: 18px;
      padding: .95rem 1rem;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text);
      line-height: 1.66;
      white-space: pre-wrap;
      word-break: break-word;
      font-size: .84rem;
    }

    .ai-bubble.user {
      background: rgba(91,140,255,.10);
      border-color: rgba(91,140,255,.20);
    }

    .ai-bubble.assistant {
      background: rgba(255,255,255,.04);
    }

    .ai-bubble.system {
      background: rgba(255,255,255,.03);
      color: var(--text-soft);
    }

    .chatbox {
      border-radius: 22px;
      background: rgba(7,11,22,.84);
      padding: .55rem;
      display: flex;
      flex-direction: column;
      gap: .45rem;
    }

    .chatbox-input {
      width: 100%;
      border: none;
      outline: none;
      background: transparent;
      color: var(--text);
      font-family: inherit;
      font-size: .87rem;
      resize: none;
      min-height: 80px;
      padding: .55rem .65rem .35rem;
      line-height: 1.6;
    }

    .chatbox-input::placeholder {
      color: rgba(148,163,184,.8);
    }

    .chatbox-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: .6rem;
      flex-wrap: wrap;
      padding: 0 .2rem .15rem;
    }

    .chatbox-toolbar-left,
    .chatbox-toolbar-right {
      display: flex;
      align-items: center;
      gap: .45rem;
      flex-wrap: wrap;
    }

    .ai-field {
      min-width: 180px;
      height: 36px;
      padding: 0 .75rem;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text);
      outline: none;
      font-size: .8rem;
      font-weight: 600;
      font-family: inherit;
    }

    .ai-response-info {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: .6rem;
      flex-wrap: wrap;
      color: var(--text-muted);
      font-size: .76rem;
      padding: 0 .2rem .05rem;
    }

    .mini-chip { height: 30px; font-size: .73rem; }

    .video-card {
      display: flex;
      flex-direction: column;
      gap: .6rem;
    }

    .hint-card {
      display: flex;
      flex-direction: column;
      gap: .9rem;
    }

    .welcome-overlay {
      position: fixed;
      inset: 0;
      z-index: 9998;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.2rem;
      background: rgba(3, 8, 18, 0.68);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .welcome-overlay.hidden {
      display: none;
    }

    .welcome-modal {
      width: min(680px, 100%);
      border-radius: 26px;
      padding: 1.25rem;
      background:
        radial-gradient(circle at top left, rgba(91,140,255,.12), transparent 30%),
        radial-gradient(circle at top right, rgba(34,197,94,.10), transparent 24%),
        var(--surface-2);
      border: 1px solid var(--border-strong);
      box-shadow: var(--shadow-lg);
      color: var(--text);
    }

    .welcome-modal-header {
      display: flex;
      align-items: center;
      gap: .75rem;
      margin-bottom: .9rem;
    }

    .welcome-modal-icon {
      width: 44px;
      height: 44px;
      border-radius: 16px;
      display: grid;
      place-items: center;
      background: rgba(34,197,94,.12);
      border: 1px solid rgba(34,197,94,.20);
      font-size: 1.2rem;
      flex: 0 0 auto;
    }

    .welcome-modal-title {
      font-size: 1.02rem;
      font-weight: 800;
      color: var(--text);
    }

    .welcome-modal-subtitle {
      font-size: .82rem;
      color: var(--text-muted);
      margin-top: .15rem;
      line-height: 1.5;
    }

    .welcome-modal-body {
      border-radius: 18px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.03);
      padding: 1rem 1rem .95rem;
      line-height: 1.7;
      color: var(--text-soft);
      font-size: .88rem;
    }

    .welcome-modal-body strong {
      color: var(--text);
    }

    .welcome-modal-body ul {
      margin: .55rem 0 0 0;
      padding-left: 1.15rem;
    }

    .welcome-modal-body li + li {
      margin-top: .35rem;
    }

    .welcome-modal-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 1rem;
    }

    .welcome-ok-btn {
      background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
      color: #fff;
      box-shadow: 0 10px 26px rgba(34,197,94,.22);
      border: 1px solid rgba(34,197,94,.28);
    }

    .welcome-ok-btn:hover {
      box-shadow: 0 16px 34px rgba(34,197,94,.28);
    }

    .submission-overlay {
      position: fixed;
      inset: 0;
      z-index: 10000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.2rem;
      background: rgba(3, 8, 18, 0.72);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .submission-overlay.hidden {
      display: none;
    }

    .submission-modal {
      width: min(520px, 100%);
      border-radius: 26px;
      padding: 1.25rem;
      background:
        radial-gradient(circle at top left, rgba(34,197,94,.14), transparent 32%),
        var(--surface-2);
      border: 1px solid rgba(34,197,94,.28);
      box-shadow: var(--shadow-lg);
      color: var(--text);
      text-align: center;
    }

    .submission-modal-icon {
      width: 54px;
      height: 54px;
      margin: 0 auto .85rem;
      border-radius: 18px;
      display: grid;
      place-items: center;
      background: rgba(34,197,94,.14);
      border: 1px solid rgba(34,197,94,.24);
      font-size: 1.55rem;
    }

    .submission-modal-title {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--text);
      margin-bottom: .45rem;
    }

    .submission-modal-text {
      font-size: .88rem;
      line-height: 1.65;
      color: var(--text-soft);
      margin-bottom: 1rem;
    }

    .submission-modal-actions {
      display: flex;
      justify-content: center;
      margin-top: 1rem;
    }

    .submission-continue-btn {
      background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
      color: #fff;
      box-shadow: 0 10px 26px rgba(34,197,94,.22);
      border: 1px solid rgba(34,197,94,.28);
      min-width: 140px;
    }

    .submission-continue-btn:hover {
      box-shadow: 0 16px 34px rgba(34,197,94,.28);
    }

    @media (max-width: 1080px) {
      .main-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }

      .panel-resizer {
        display: none;
      }

      #code-editor {
        height: 420px;
      }

      .top-timer-group {
        width: 100%;
        justify-content: flex-start;
      }

      #video-panel {
        transition: opacity 220ms ease, transform 260ms ease, visibility 0s linear 0s;
        height: auto;
      }

      .main-grid.video-hidden #video-panel {
        display: none;
      }
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <section class="main-grid" id="main-grid">
      <div class="left-panel stack">
        <div class="card">
          <div class="top-timer-group" style="margin-bottom: .9rem;">
            <div class="header-timer" id="header-timer">
              <span class="header-timer-dot"></span>
              <span class="header-timer-label">Χρόνος</span>
              <span class="header-timer-value" id="header-timer-value">00:00</span>
            </div>

            <button id="start-timer-btn" class="ghost">
              <span class="icon">⏱</span> Έναρξη χρόνου
            </button>

            <button id="stop-timer-btn" class="ghost">
              <span class="icon">⏹</span> Λήξη χρόνου
            </button>

            <button id="submit-btn" class="secondary">
              <span class="icon">💾</span> Υποβολή
            </button>

            <div class="status-badges">
              <button id="fullscreen-btn" class="secondary">
                <span class="icon">⛶</span> Full screen
              </button>

              <button id="toggle-video-btn" class="secondary">
                <span class="icon">👁</span> Show video
              </button>
            </div>
          </div>

          <div class="card-header-line">
            <div class="card-title-block">
              <div class="card-title">
                <span class="card-title-icon">💻</span>
                <span>Python Code Editor</span>
              </div>
            </div>

            <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center;">
              <span class="badge">Runs <strong id="run-count">0</strong></span>
              <span class="badge">Events <strong id="event-count">0</strong></span>
              <span class="badge badge--dirty" id="dirty-indicator">
                <span class="badge-dot"></span> Unsaved changes
              </span>
              <button id="run-btn" class="primary">
                <span class="icon">▶</span> Run
              </button>
              <button id="reset-btn" class="ghost">
                <span class="icon">↺</span> Καθαρισμός
              </button>
            </div>
          </div>

          <div class="editor-wrapper">
            <div class="editor-header">
              <div class="editor-header-left">
                <div class="dot-row">
                  <span></span><span></span><span></span>
                </div>
                <span>main.py</span>
              </div>
              <div class="editor-header-right">
                <span id="char-count">0 chars</span>
              </div>
            </div>

            <div id="code-editor"></div>

            <div class="editor-footer">
              <span><span class="status-dot"></span> Python</span>
              <span id="last-run-status">Καμία εκτέλεση ακόμη</span>
            </div>
          </div>

          <div class="action-bar">
            <div class="utility-row"></div>
            <div class="status-bar">
              <span class="status-pill">
                <span class="status-dot" id="status-dot"></span>
                <span id="global-status-text">Έτοιμο για εκτέλεση</span>
              </span>
              <span class="timer-text">
                Χρόνος: <span class="timer-number" id="timer">0s</span>
              </span>
            </div>
          </div>

          <div class="result-wrapper" id="python-runner-result">
            <div class="result-header">
              <span id="result-header-title">Python Runner</span>
              <span id="test-summary">0 scripts executed</span>
            </div>
            <div class="result-body" id="result-body"></div>
          </div>
        </div>
      </div>

      <div class="panel-resizer" id="panel-resizer" aria-hidden="true"></div>

      <aside class="stack" id="video-panel">
        <div class="video-card">
          <div class="card-title">
            <span class="card-title-icon">🤖</span>
            <span>AI Assistant</span>
          </div>

          <p>Χρησιμοποίησε το AI μόνο για hints και καθοδήγηση.</p>

          <div class="auth-shell">
            <div class="auth-box">
              <div id="google-btn"></div>
            </div>
          </div>

          <div id="user-panel" class="user-inline" style="display:none;">
            <div class="user-inline-left">
              <img id="user-picture" class="user-avatar" src="" alt="avatar" referrerpolicy="no-referrer" />
              <div class="user-meta">
                <strong id="user-name"></strong>
              </div>
            </div>

            <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
              <button id="logout-btn" class="secondary">
                <span class="icon">🚪</span> Αποσύνδεση
              </button>
            </div>
          </div>

          <div class="chatbox">
            <textarea
              id="ai-prompt"
              spellcheck="false"
              class="chatbox-input"
              placeholder="Plan, @ για context, / για commands"
            ></textarea>

            <div class="chatbox-toolbar">
              <div class="chatbox-toolbar-left">
                <select id="ai-model" class="ai-field">
                  <option value="gemini-2.5-flash" selected>gemini-2.5-flash</option>
                  <option value="gemini-2.5-pro">gemini-2.5-pro</option>
                </select>
                <span class="mini-chip" id="ai-status-mini">Idle</span>
              </div>

              <div class="chatbox-toolbar-right">
                <button id="ai-clear-btn" class="secondary">
                  <span class="icon">🧹</span> Καθαρισμός
                </button>
                <button id="ai-run-btn" class="primary">
                  <span class="icon">✨</span> Ask Gemini
                </button>
              </div>
            </div>

            <div class="ai-response-info">
              <span>Hints μόνο, όχι πλήρης λύση</span>
              <span id="ai-last-run">Καμία κλήση ακόμη</span>
            </div>
          </div>
        </div>

        <div class="hint-card">
          <div class="card-title">
            <span class="card-title-icon">💬</span>
            <span>Gemini Response</span>
          </div>

          <div class="result-wrapper" style="margin-top:0;">
            <div class="result-header">
              <span>Responses</span>
              <span id="ai-summary">0 responses</span>
            </div>
            <div class="result-body">
              <div class="ai-thread" id="ai-result-body"></div>
            </div>
          </div>
        </div>
      </aside>
    </section>
  </div>

  <div id="welcome-overlay" class="welcome-overlay">
    <div class="welcome-modal" role="dialog" aria-modal="true" aria-labelledby="welcome-title">
      <div class="welcome-modal-header">
        <div class="welcome-modal-icon">🧪</div>
        <div>
          <div id="welcome-title" class="welcome-modal-title">Οδηγίες Πειράματος</div>
          <div class="welcome-modal-subtitle">Διάβασε προσεκτικά πριν ξεκινήσεις.</div>
        </div>
      </div>

      <div class="welcome-modal-body">
        <strong>Καλώς ήρθες στο πείραμα.</strong><br>
        Παρακαλώ ακολούθησε τις οδηγίες της άσκησης και χρησιμοποίησε το περιβάλλον μόνο για τον σκοπό του task.
        <ul>
          <li>Χρησιμοποίησε τον editor για να γράψεις και να τρέξεις Python κώδικα.</li>
          <li>Χρησιμοποίησε το <strong>AI Assistant</strong> μόνο για σύντομα hints και καθοδήγηση.</li>
          <li>Για να χρησιμοποιήσεις το AI, <strong>πρέπει να συνδεθείς με το Gmail / Google account σου</strong>.</li>
          <li>Όταν ολοκληρώσεις, κάνε υποβολή ώστε να αποθηκευτεί το log του πειράματος.</li>
        </ul>
      </div>

      <div class="welcome-modal-actions">
        <button id="welcome-ok-btn" class="welcome-ok-btn">
          <span class="icon">✓</span> OK
        </button>
      </div>
    </div>
  </div>

  <div id="submission-success-overlay" class="submission-overlay hidden">
    <div class="submission-modal" role="dialog" aria-modal="true" aria-labelledby="submission-success-title">
      <div class="submission-modal-icon">✅</div>

      <div id="submission-success-title" class="submission-modal-title">
        Επιτυχής υποβολή
      </div>

      <div id="submission-success-message" class="submission-modal-text">
        Η υποβολή ολοκληρώθηκε με επιτυχία.
      </div>

      <div class="submission-modal-actions">
        <button id="submission-continue-btn" class="submission-continue-btn">
          Συνέχεια
        </button>
      </div>
    </div>
  </div>

  <div id="toast-container" class="toast-container"></div>

  <script>
    let events = [];
    let runCount = 0;
    let totalEvents = 0;
    let isDirty = false;

    let timerRunning = false;
    let timerInterval = null;
    let timerStartTime = null;
    let elapsedMs = 0;

    let aiRunCount = 0;
    let loggedInUser = null;

    const EXPERIMENT_CONDITION = 'ai_assistance';
    const EXPERIMENT_TASK_ID = 'decreasing_monotonic_stack_v1';

    let editorInstance = null;
    let pyodide = null;
    let pyodideReady = false;

    let rightPanelVisible = true;
    let isFullscreen = false;

    const LAB_MODE = <?php echo LAB_MODE ? 'true' : 'false'; ?>;
    const DRAFT_KEY = 'python.ai.draft.v1';

    const mainLayout = document.getElementById('main-grid');
    const rightPanel = document.getElementById('video-panel');
    const panelResizer = document.getElementById('panel-resizer');

    const charCountSpan  = document.getElementById('char-count');
    const runCountSpan   = document.getElementById('run-count');
    const eventCountSpan = document.getElementById('event-count');
    const dirtyBadge     = document.getElementById('dirty-indicator');
    const lastRunStatus  = document.getElementById('last-run-status');
    const timerSpan      = document.getElementById('timer');
    const headerTimer    = document.getElementById('header-timer');
    const headerTimerValue = document.getElementById('header-timer-value');
    const statusDot      = document.getElementById('status-dot');
    const statusText     = document.getElementById('global-status-text');
    const resultBody     = document.getElementById('result-body');
    const testSummary    = document.getElementById('test-summary');
    const resultHeaderTitle = document.getElementById('result-header-title');

    const aiModelSelect = document.getElementById('ai-model');
    const aiPromptArea  = document.getElementById('ai-prompt');
    const aiRunBtn      = document.getElementById('ai-run-btn');
    const aiClearBtn    = document.getElementById('ai-clear-btn');
    const aiResultBody  = document.getElementById('ai-result-body');
    const aiSummary     = document.getElementById('ai-summary');
    const aiLastRun     = document.getElementById('ai-last-run');
    const aiStatusMini  = document.getElementById('ai-status-mini');

    const userPanel    = document.getElementById('user-panel');
    const userPicture  = document.getElementById('user-picture');
    const userName     = document.getElementById('user-name');
    const logoutBtn    = document.getElementById('logout-btn');

    const welcomeOverlay = document.getElementById('welcome-overlay');
    const welcomeOkBtn   = document.getElementById('welcome-ok-btn');

    const submissionSuccessOverlay = document.getElementById('submission-success-overlay');
    const submissionSuccessMessage = document.getElementById('submission-success-message');
    const submissionContinueBtn = document.getElementById('submission-continue-btn');

    const fullscreenBtn = document.getElementById('fullscreen-btn');
    const toggleVideoBtn = document.getElementById('toggle-video-btn');

    const defaultPythonCode = [
      'print("Hello from Python")',
      '',
      '# Παράδειγμα με input:',
      '# a = int(input())',
      '# b = int(input())',
      '# print(a + b)',
      ''
    ].join('\n');

    function recordEvent(type, extra = {}) {
      const ev = { type, timestamp: Date.now(), ...extra };
      events.push(ev);
      totalEvents++;
      eventCountSpan.textContent = totalEvents;
    }

    function escapeHtml(str) {
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
    }

    function formatTextToHtml(text) {
      return escapeHtml(String(text || '')).replace(/\n/g, '<br>');
    }

    function setStatusNeutral(msg) {
      statusDot.classList.remove('status-dot--ok', 'status-dot--err');
      statusText.textContent = msg;
    }

    function setStatusError(msg) {
      statusDot.classList.remove('status-dot--ok');
      statusDot.classList.add('status-dot--err');
      statusText.textContent = msg;
    }

    function setStatusOk(msg) {
      statusDot.classList.remove('status-dot--err');
      statusDot.classList.add('status-dot--ok');
      statusText.textContent = msg;
    }

    function updateCharCountFromValue(value) {
      const len = value.length;
      charCountSpan.textContent = len + " chars";
    }

    function updateDirtyState() {
      dirtyBadge.style.opacity = isDirty ? 1 : 0.45;
    }

    function showToast(message, type = "success") {
      const container = document.getElementById("toast-container");
      const el = document.createElement("div");
      el.className = `toast ${type}`;
      el.textContent = message;
      container.appendChild(el);

      setTimeout(() => {
        el.style.opacity = "0";
        el.style.transform = "translateY(8px)";
        setTimeout(() => el.remove(), 180);
      }, 2600);
    }

    function setButtonLoading(btn, loading, loadingHtml, normalHtml) {
      btn.disabled = loading;
      btn.innerHTML = loading ? loadingHtml : normalHtml;
    }

    function showResultMessage(html) {
      resultBody.innerHTML = `
        <div class="empty-state">
          <div class="empty-state-icon">🐍</div>
          <div class="empty-state-title">Έτοιμο για δοκιμή</div>
          <div class="empty-state-text">${html}</div>
        </div>
      `;
      testSummary.textContent = `0 scripts executed`;
    }

    function aiSetMessage(html) {
      aiResultBody.innerHTML = `<div class="ai-bubble system">${html}</div>`;
    }

    function appendAiBubble(type, html) {
      const bubble = document.createElement('div');
      bubble.className = `ai-bubble ${type}`;
      bubble.innerHTML = html;
      aiResultBody.appendChild(bubble);
      aiResultBody.scrollTop = aiResultBody.scrollHeight;
    }

    function clearAiThread() {
      aiResultBody.innerHTML = '';
      aiRunCount = 0;
      aiSummary.textContent = '0 responses';
    }

    function updateAiButtonState() {
      const enabled = !!loggedInUser;
      aiRunBtn.disabled = !enabled;
    }

    function showWelcomePopup() {
      welcomeOverlay.classList.remove('hidden');
    }

    function hideWelcomePopup() {
      welcomeOverlay.classList.add('hidden');
    }

    function showSubmissionSuccessPopup(message) {
      submissionSuccessMessage.textContent = message || 'Η υποβολή ολοκληρώθηκε με επιτυχία.';
      submissionSuccessOverlay.classList.remove('hidden');
    }

    function hideSubmissionSuccessPopup() {
      submissionSuccessOverlay.classList.add('hidden');
    }

    function getEditorValue() {
      return editorInstance ? editorInstance.getValue() : '';
    }

    function setEditorValue(value) {
      if (editorInstance) {
        editorInstance.setValue(value);
      }
      updateCharCountFromValue(value);
    }

    function saveDraftFromValue(value) {
      try {
        localStorage.setItem(DRAFT_KEY, value);
      } catch {}
    }

    function loadDraftValue() {
      try {
        const draft = localStorage.getItem(DRAFT_KEY);
        if (typeof draft === 'string' && draft.length > 0) {
          return draft;
        }
      } catch {}
      return defaultPythonCode;
    }

    async function initMonaco() {
      return new Promise((resolve) => {
        require.config({
          paths: {
            vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min/vs'
          }
        });

        require(['vs/editor/editor.main'], function () {
          const initialValue = loadDraftValue();

          editorInstance = monaco.editor.create(document.getElementById('code-editor'), {
            value: initialValue,
            language: 'python',
            theme: 'vs-dark',
            automaticLayout: true,
            minimap: { enabled: true },
            fontSize: 14,
            tabSize: 4,
            insertSpaces: true,
            wordWrap: 'off',
            scrollBeyondLastLine: false,
            renderWhitespace: 'selection',
            quickSuggestions: true,
            suggestOnTriggerCharacters: true,
            acceptSuggestionOnEnter: 'on',
            bracketPairColorization: { enabled: true },
            guides: { indentation: true },
            folding: true,
            lineNumbers: 'on',
            roundedSelection: false,
            cursorSmoothCaretAnimation: 'on',
            smoothScrolling: true
          });

          editorInstance.onDidChangeModelContent(() => {
            const value = editorInstance.getValue();
            updateCharCountFromValue(value);
            isDirty = true;
            updateDirtyState();
            saveDraftFromValue(value);
            recordEvent('input', { length: value.length });
          });

          updateCharCountFromValue(initialValue);
          resolve();
        });
      });
    }

    async function initPyodideRuntime() {
      if (pyodideReady) return;
      setStatusNeutral('Φόρτωση Python runtime...');
      pyodide = await loadPyodide();
      pyodideReady = true;
      setStatusOk('Python runtime έτοιμο.');
    }

    async function runPythonScript(userCode) {
      let stdoutBuffer = '';

      pyodide.setStdout({
        batched: (msg) => {
          stdoutBuffer += msg + '\n';
        }
      });

      try {
        await pyodide.runPythonAsync(userCode);

        resultBody.innerHTML = `
          <div class="test-list">
            <div class="test-item">
              <div class="test-title-row">
                <div class="test-name"><span>▶</span><span>Program Output</span></div>
                <div class="test-status test-status--ok"><span>●</span><span>Executed</span></div>
              </div>
              <div class="test-meta">Το script εκτελέστηκε κανονικά.</div>
              <div class="test-output">${escapeHtml(stdoutBuffer || '(no output)')}</div>
            </div>
          </div>
        `;

        testSummary.textContent = `1 script executed`;
        lastRunStatus.textContent = "Τελευταία εκτέλεση: script ok";
        setStatusOk("Το Python script εκτελέστηκε σωστά.");
      } catch (err) {
        resultBody.innerHTML = `
          <div class="test-list">
            <div class="test-item">
              <div class="test-title-row">
                <div class="test-name"><span>!</span><span>Program Error</span></div>
                <div class="test-status test-status--err"><span>!</span><span>Error</span></div>
              </div>
              <div class="test-meta">Το script ξεκίνησε αλλά απέτυχε κατά την εκτέλεση.</div>
              <div class="test-output">${escapeHtml(stdoutBuffer ? stdoutBuffer + "\n\n" : "")}${escapeHtml(String(err))}</div>
            </div>
          </div>
        `;

        testSummary.textContent = `1 script executed (with error)`;
        lastRunStatus.textContent = "Τελευταία εκτέλεση: script error";
        setStatusError("Το Python script απέτυχε.");
      } finally {
        pyodide.setStdout({ batched: () => {} });
      }
    }

    function formatHeaderTimer(totalSeconds) {
      const mins = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
      const secs = String(totalSeconds % 60).padStart(2, '0');
      return `${mins}:${secs}`;
    }

    function updateTimerDisplay() {
      let ms = elapsedMs;
      if (timerRunning && timerStartTime !== null) {
        ms = elapsedMs + (Date.now() - timerStartTime);
      }
      const sec = Math.floor(ms / 1000);
      timerSpan.textContent = sec + "s";
      headerTimerValue.textContent = formatHeaderTimer(sec);
      headerTimer.classList.toggle('is-running', timerRunning);
    }

    function startTimer() {
      if (timerRunning) return;
      timerRunning = true;
      timerStartTime = Date.now();
      recordEvent('timer_start');
      setStatusOk("Μετράει ο χρόνος του task...");

      if (timerInterval) clearInterval(timerInterval);
      timerInterval = setInterval(updateTimerDisplay, 1000);
      updateTimerDisplay();
    }

    function stopTimer() {
      if (!timerRunning) return;
      timerRunning = false;
      if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
      }
      if (timerStartTime !== null) {
        elapsedMs += Date.now() - timerStartTime;
        timerStartTime = null;
      }
      recordEvent('timer_stop', { elapsed_ms: elapsedMs });
      updateTimerDisplay();
      setStatusNeutral("Ο χρόνος σταμάτησε.");
    }

    async function runCode() {
      const runBtn = document.getElementById('run-btn');
      const runnerResult = document.getElementById('python-runner-result');

      runnerResult?.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });

      recordEvent('run_click');
      runCount++;
      runCountSpan.textContent = runCount.toString();
      lastRunStatus.textContent = "Εκτέλεση...";
      setStatusNeutral("Εκτέλεση Python...");

      setButtonLoading(
        runBtn,
        true,
        '<span class="icon">⏳</span> Running...',
        '<span class="icon">▶</span> Run'
      );

      try {
        await initPyodideRuntime();

        const userCode = getEditorValue();
        if (!userCode.trim()) {
          setStatusError("Δεν υπάρχει κώδικας για εκτέλεση.");
          lastRunStatus.textContent = "Δεν υπάρχει κώδικας.";
          showResultMessage("Πρόσθεσε πρώτα Python κώδικα.");
          return;
        }

        await runPythonScript(userCode);

        requestAnimationFrame(() => {
          runnerResult?.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        });
      } catch (err) {
        setStatusError("Σφάλμα κατά την εκτέλεση Python.");
        lastRunStatus.textContent = "Σφάλμα στην εκτέλεση.";
        showResultMessage("Σφάλμα κατά την εκτέλεση:<br><code>" + escapeHtml(String(err)) + "</code>");

        requestAnimationFrame(() => {
          runnerResult?.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
          });
        });
      } finally {
        setButtonLoading(
          runBtn,
          false,
          '',
          '<span class="icon">▶</span> Run'
        );
      }
    }

    document.getElementById('start-timer-btn').addEventListener('click', startTimer);
    document.getElementById('stop-timer-btn').addEventListener('click', stopTimer);
    document.getElementById('run-btn').addEventListener('click', runCode);

    document.getElementById('submit-btn').addEventListener('click', () => {
      recordEvent('submit_click');
      sendLogs();
    });

    function normalizeOutput(text) {
      return String(text || '')
        .trim()
        .replace(/\r/g, '')
        .replace(/[,\[\]]/g, ' ')
        .replace(/\s+/g, ' ');
    }

    function parseNumbersFromOutput(text) {
      const normalized = String(text || '').replace(/[,\[\]]/g, ' ');
      const matches = normalized.match(/-?\d+/g);
      return matches ? matches.map(Number) : [];
    }

    function isDecreasingArray(values) {
      if (!Array.isArray(values) || values.length === 0) return false;

      for (let i = 0; i < values.length - 1; i++) {
        if (values[i] < values[i + 1]) {
          return false;
        }
      }

      return true;
    }

    async function runUserCodeWithInput(userCode, inputText) {
      await initPyodideRuntime();

      pyodide.globals.set('__USER_CODE__', userCode);
      pyodide.globals.set('__INPUT_TEXT__', inputText);

      const wrapper = `
import sys, io, builtins, traceback, json

_user_code = __USER_CODE__
_input_text = __INPUT_TEXT__
_input_lines = iter(_input_text.splitlines())

def _mock_input(prompt=''):
    return next(_input_lines)

_old_input = builtins.input
_old_stdout = sys.stdout
_old_stderr = sys.stderr

_out = io.StringIO()
_err = io.StringIO()

result = {
    "ok": True,
    "stdout": "",
    "stderr": "",
    "error": ""
}

try:
    builtins.input = _mock_input
    sys.stdout = _out
    sys.stderr = _err

    namespace = {}
    exec(_user_code, namespace, namespace)

except Exception:
    result["ok"] = False
    result["error"] = traceback.format_exc()

finally:
    result["stdout"] = _out.getvalue()
    result["stderr"] = _err.getvalue()

    builtins.input = _old_input
    sys.stdout = _old_stdout
    sys.stderr = _old_stderr

json.dumps(result, ensure_ascii=False)
`;

      const raw = await pyodide.runPythonAsync(wrapper);
      return JSON.parse(raw);
    }

    async function evaluateObjectiveTests(userCode) {
      const tests = [
        {
          key: 'visible',
          input: '7\n8 6 6 7 5 5 9\n',
          expected: '9'
        },
        {
          key: 'hidden_1',
          input: '7\n10 2 8 1 7 6 5\n',
          expected: '10 8 7 6 5'
        },
        {
          key: 'hidden_2',
          input: '7\n4 4 3 5 5 2 6\n',
          expected: '6'
        }
      ];

      const results = {};

      for (const test of tests) {
        const run = await runUserCodeWithInput(userCode, test.input);
        const actualOutput = run.ok ? run.stdout : run.error;
        const passed = run.ok && normalizeOutput(actualOutput) === normalizeOutput(test.expected);

        results[test.key] = {
          passed,
          output: actualOutput,
          expected: test.expected
        };
      }

      const decreasingPropertyPassed = ['visible', 'hidden_1', 'hidden_2'].every(key => {
        const nums = parseNumbersFromOutput(results[key].output);
        return isDecreasingArray(nums);
      });

      return {
        visible_test_passed: results.visible.passed,
        visible_test_output: results.visible.output,

        hidden_test_1_passed: results.hidden_1.passed,
        hidden_test_1_output: results.hidden_1.output,

        hidden_test_2_passed: results.hidden_2.passed,
        hidden_test_2_output: results.hidden_2.output,

        decreasing_property_passed: decreasingPropertyPassed
      };
    }

    async function sendLogs() {
      const submitBtn = document.getElementById('submit-btn');

      if (!loggedInUser?.sub && !window.currentParticipantId) {
        showToast('⚠️ Πρέπει να συνδεθείς πριν την υποβολή.', 'error');
        return;
      }

      const userCode = getEditorValue();

      if (!userCode.trim()) {
        showToast('⚠️ Δεν υπάρχει κώδικας για υποβολή.', 'error');
        return;
      }

      setButtonLoading(
        submitBtn,
        true,
        '<span class="icon">⏳</span> Testing...',
        '<span class="icon">💾</span> Υποβολή'
      );

      try {
        const objectiveResults = await evaluateObjectiveTests(userCode);

        const payload = {
          action: 'save_submission',

          user_id: loggedInUser?.sub || window.currentParticipantId,
          condition: EXPERIMENT_CONDITION,
          task_id: EXPERIMENT_TASK_ID,
          code_snapshot: userCode,

          visible_test_passed: objectiveResults.visible_test_passed,
          visible_test_output: objectiveResults.visible_test_output,

          hidden_test_1_passed: objectiveResults.hidden_test_1_passed,
          hidden_test_1_output: objectiveResults.hidden_test_1_output,

          hidden_test_2_passed: objectiveResults.hidden_test_2_passed,
          hidden_test_2_output: objectiveResults.hidden_test_2_output,

          decreasing_property_passed: objectiveResults.decreasing_property_passed
        };

        setButtonLoading(
          submitBtn,
          true,
          '<span class="icon">⏳</span> Saving...',
          '<span class="icon">💾</span> Υποβολή'
        );

        const res = await fetch('', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json; charset=utf-8' },
          body: JSON.stringify(payload)
        });

        const data = await res.json();

        if (data.success) {
          showSubmissionSuccessPopup('Η υποβολή ολοκληρώθηκε με επιτυχία. Πάτησε Συνέχεια για να προχωρήσεις στο επόμενο ερωτηματολόγιο.');

          events = [];
          totalEvents = 0;
          eventCountSpan.textContent = '0';
          isDirty = false;
          updateDirtyState();

          console.log('Objective log saved:', data.data);
        } else {
          showToast('⚠️ Σφάλμα κατά την αποθήκευση των objective logs.', 'error');
          console.error(data);
        }
      } catch (err) {
        console.error('Σφάλμα:', err);
        showToast('⚠️ Σφάλμα κατά την εκτέλεση των tests ή την αποθήκευση.', 'error');
      } finally {
        setButtonLoading(
          submitBtn,
          false,
          '',
          '<span class="icon">💾</span> Υποβολή'
        );
      }
    }

    document.getElementById('reset-btn').addEventListener('click', () => {
      recordEvent('reset_click');
      setEditorValue(defaultPythonCode);
      isDirty = true;
      updateDirtyState();
      saveDraftFromValue(defaultPythonCode);
      showResultMessage("Ο κώδικας καθαρίστηκε. Μπορείς να ξεκινήσεις από την αρχή.");
      setStatusNeutral("Περιμένει νέο κώδικα.");
      lastRunStatus.textContent = "Καμία εκτέλεση ακόμη";
      showToast('Ο editor καθαρίστηκε.', 'info');
    });

    aiClearBtn.addEventListener('click', () => {
      aiPromptArea.value = "";
      aiSetMessage("Το prompt καθαρίστηκε.");
      aiStatusMini.textContent = "Idle";
    });

    aiRunBtn.addEventListener('click', runAiPrompt);
    welcomeOkBtn.addEventListener('click', hideWelcomePopup);

    submissionContinueBtn.addEventListener('click', () => {
      hideSubmissionSuccessPopup();
      window.location.href = 'post_questions.php';
    });

    function runAiPrompt() {
      const prompt = (aiPromptArea.value || "").trim();
      const model  = aiModelSelect.value;

      if (!loggedInUser) {
        aiSetMessage("Πρέπει να συνδεθείς με Google πρώτα.");
        return;
      }
      if (!prompt) {
        aiSetMessage("Χρειάζεται <strong>prompt</strong>.");
        return;
      }

      appendAiBubble('user', formatTextToHtml(prompt));

      aiStatusMini.textContent = "Calling...";
      aiLastRun.textContent = "Κλήση σε Gemini...";

      setButtonLoading(
        aiRunBtn,
        true,
        '<span class="icon">⏳</span> Thinking...',
        '<span class="icon">✨</span> Ask Gemini'
      );

      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify({
          action: 'gemini_generate',
          prompt,
          model
        })
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'ok') {
          aiRunCount++;
          aiSummary.textContent = `${aiRunCount} responses`;

          const text = data.text ? formatTextToHtml(data.text) : "(Κενή απάντηση)";
          const rl = data.rate_limit;

          if (data.participant_id !== undefined) {
            window.currentParticipantId = data.participant_id || '';
          }

          appendAiBubble('assistant', `
            <div style="display:flex;justify-content:space-between;gap:0.6rem;flex-wrap:wrap;margin-bottom:0.45rem;">
              <strong>${escapeHtml(model)}</strong>
              <span style="color:var(--text-muted);font-size:0.76rem;">Όριο: ${rl?.limit ?? '-'} / ώρα • Υπόλοιπο: ${rl?.remaining ?? '-'}</span>
            </div>
            ${text}
          `);

          aiPromptArea.value = "";
          aiStatusMini.textContent = "OK";
          aiLastRun.textContent = "Τελευταία κλήση: ολοκληρώθηκε";
        } else {
          const rl = data.rate_limit;
          aiStatusMini.textContent = "Error";
          aiLastRun.textContent = "Σφάλμα στην κλήση";

          let html = `⚠️ ${escapeHtml(data.message || 'Άγνωστο σφάλμα')}`;
          if (rl && rl.reset_in != null) {
            html += `<br><br>Δοκίμασε ξανά σε ~<strong>${Math.ceil(rl.reset_in/60)}</strong> λεπτά.`;
          }

          appendAiBubble('assistant', html);
          if (data.details) console.error('Gemini details:', data.details);
          if (data.http_code) console.error('Gemini HTTP:', data.http_code);
        }
      })
      .catch(err => {
        aiStatusMini.textContent = "Error";
        aiLastRun.textContent = "Σφάλμα σύνδεσης";
        appendAiBubble('assistant', "⚠️ Σφάλμα σύνδεσης με τον server.");
        console.error(err);
      })
      .finally(() => {
        setButtonLoading(
          aiRunBtn,
          false,
          '',
          '<span class="icon">✨</span> Ask Gemini'
        );
      });
    }

    function showUser(user) {
      loggedInUser = user;

      document.getElementById('google-btn').style.display = "none";
      document.querySelector('.auth-shell').style.display = "none";

      userPanel.style.display = "flex";

      const pic = (user.picture || "").trim();

      if (pic) {
        userPicture.onload = () => {
          userPicture.style.display = "block";
        };

        userPicture.onerror = () => {
          userPicture.style.display = "none";
          console.warn("Avatar failed to load:", pic);
        };

        userPicture.referrerPolicy = "no-referrer";
        userPicture.src = pic;
      } else {
        userPicture.removeAttribute("src");
        userPicture.style.display = "none";
      }

      userName.textContent = user.name || "User";

      updateAiButtonState();
      showToast('✅ Η σύνδεση με Google ολοκληρώθηκε. Μπορείς τώρα να χρησιμοποιήσεις το Gemini.', 'success');
      clearAiThread();
      aiSetMessage("Νέα συνομιλία ξεκίνησε για αυτή τη σύνδεση.");
      aiStatusMini.textContent = "Ready";
      aiLastRun.textContent = "Νέα συνεδρία Gemini";
    }

    function hideUser() {
      loggedInUser = null;

      document.getElementById('google-btn').style.display = "";
      document.querySelector('.auth-shell').style.display = "";

      userPanel.style.display = "none";
      userPicture.removeAttribute("src");
      userPicture.style.display = "none";

      updateAiButtonState();
      aiSetMessage("Συνδέσου με Google και μετά γράψε prompt.");
    }

    function backendWhoAmI() {
      return fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify({ action: 'whoami' })
      }).then(res => res.json());
    }

    function backendLogin(credential) {
      return fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify({ action: 'google_login', credential })
      }).then(res => res.json());
    }

    function backendLogout() {
      return fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify({ action: 'google_logout' })
      }).then(res => res.json());
    }

    logoutBtn.addEventListener('click', () => {
      backendLogout().then(() => {
        hideUser();
      });
    });

    function initGoogleButton() {
      if (window.google && google.accounts && google.accounts.id) {
        google.accounts.id.initialize({
          client_id: "<?php echo htmlspecialchars(GOOGLE_CLIENT_ID, ENT_QUOTES); ?>",
          callback: (response) => {
            const credential = response.credential;
            backendLogin(credential).then(data => {
              if (data.status === 'ok') {
                showUser(data.user);
              } else {
                hideUser();
                showToast("⚠️ Αποτυχία σύνδεσης Google.", 'error');
              }
            }).catch(() => {
              hideUser();
              showToast("⚠️ Σφάλμα σύνδεσης με τον server.", 'error');
            });
          }
        });

        google.accounts.id.renderButton(
          document.getElementById("google-btn"),
          {
            theme: "outline",
            size: "large",
            shape: "pill",
            text: "signin_with"
          }
        );
      } else {
        const btn = document.getElementById("google-btn");
        btn.innerHTML = "<div style='font-size:.85rem;color:#bfd0ef;'>Δεν φορτώθηκε το Google Sign-In script.</div>";
      }
    }

    function setRightPanelVisible(visible) {
      rightPanelVisible = visible;
      mainLayout.classList.toggle('video-hidden', !visible);
      toggleVideoBtn.innerHTML = visible
        ? '<span class="icon">👁</span> Hide Chatbot'
        : '<span class="icon">👁</span> Show Chatbot';
    }

    function applyPanelSplit(leftPercent) {
      if (window.innerWidth <= 1080) {
        mainLayout.style.gridTemplateColumns = '';
        return;
      }

      const shellRect = mainLayout.getBoundingClientRect();
      const totalWidth = shellRect.width;
      const resizerWidth = 18;
      const minLeftPx = 420;
      const minRightPx = 280;
      const maxRightPx = 700;

      let safe = Math.max(25, Math.min(75, leftPercent));

      let leftPx = (safe / 100) * totalWidth;
      let rightPx = totalWidth - leftPx - resizerWidth;

      if (leftPx < minLeftPx) {
        leftPx = minLeftPx;
        rightPx = totalWidth - leftPx - resizerWidth;
      }

      if (rightPx < minRightPx) {
        rightPx = minRightPx;
        leftPx = totalWidth - rightPx - resizerWidth;
      }

      if (rightPx > maxRightPx) {
        rightPx = maxRightPx;
        leftPx = totalWidth - rightPx - resizerWidth;
      }

      const finalLeftPercent = (leftPx / totalWidth) * 100;
      const finalRightPercent = (rightPx / totalWidth) * 100;

      mainLayout.style.gridTemplateColumns = `${finalLeftPercent}% ${resizerWidth}px ${finalRightPercent}%`;
    }

    toggleVideoBtn.addEventListener('click', () => {
      setRightPanelVisible(!rightPanelVisible);
    });

    fullscreenBtn.addEventListener('click', async () => {
      try {
        if (!document.fullscreenElement) {
          await document.documentElement.requestFullscreen();
          isFullscreen = true;
        } else {
          await document.exitFullscreen();
          isFullscreen = false;
        }
      } catch (e) {
        console.error(e);
      }
    });

    document.addEventListener('fullscreenchange', () => {
      isFullscreen = !!document.fullscreenElement;

      document.documentElement.classList.toggle('is-fullscreen', isFullscreen);
      document.body.classList.toggle('is-fullscreen', isFullscreen);

      fullscreenBtn.innerHTML = isFullscreen
        ? '<span class="icon">🡼</span> Exit full screen'
        : '<span class="icon">⛶</span> Full screen';
    });

    (function initResizer() {
      let dragging = false;

      panelResizer.addEventListener('mousedown', (e) => {
        if (window.innerWidth <= 1080 || !rightPanelVisible) return;
        dragging = true;
        panelResizer.classList.add('is-dragging');
        document.body.style.userSelect = 'none';
        e.preventDefault();
      });

      window.addEventListener('mousemove', (e) => {
        if (!dragging || window.innerWidth <= 1080 || !rightPanelVisible) return;

        const shellRect = mainLayout.getBoundingClientRect();
        const offset = e.clientX - shellRect.left;
        const percent = (offset / shellRect.width) * 100;

        applyPanelSplit(percent);
      });

      window.addEventListener('mouseup', () => {
        dragging = false;
        panelResizer.classList.remove('is-dragging');
        document.body.style.userSelect = '';
      });
    })();

    window.addEventListener('keydown', (e) => {
      const isMod = e.ctrlKey || e.metaKey;

      if (isMod && !e.shiftKey && e.key === 'Enter') {
        e.preventDefault();
        runCode();
      }

      if (isMod && e.shiftKey && e.key === 'Enter') {
        e.preventDefault();
        runAiPrompt();
      }

      if (isMod && e.key.toLowerCase() === 's') {
        e.preventDefault();
        sendLogs();
      }
    });

    window.onload = () => {
      isDirty = false;
      updateDirtyState();
      setStatusNeutral("Έτοιμο για εκτέλεση");
      updateTimerDisplay();
      updateAiButtonState();
      resultHeaderTitle.textContent = "Python Runner";
      showWelcomePopup();
      setRightPanelVisible(true);

      if (window.innerWidth > 1080) {
        const shellRect = mainLayout.getBoundingClientRect();
        const initialLeftPercent = ((shellRect.width - 18 - 280) / shellRect.width) * 100;
        applyPanelSplit(initialLeftPercent);
      }

      initMonaco().then(() => {
        showResultMessage("Πάτησε <strong>Run</strong> για να τρέξεις γενικό Python script με υποστήριξη για <code>print()</code>.");
      });

      if (LAB_MODE) {
        hideUser();
        initGoogleButton();
      } else {
        backendWhoAmI().then(data => {
          if (data.logged_in && data.user) showUser(data.user);
          else hideUser();

          if (data.participant_id !== undefined) {
            window.currentParticipantId = data.participant_id || '';
          }

          initGoogleButton();
        }).catch(() => {
          hideUser();
          initGoogleButton();
        });
      }
    };
  </script>
</body>
</html>