<?php
session_start();

/**
 * --------------- CONFIG ---------------
 */
const GOOGLE_CLIENT_ID = '838912664090-vvj7itsf3b813j27c8l7ea4asj6093na.apps.googleusercontent.com';
const LAB_MODE = true;
const AI_RATE_LIMIT_MAX = 50;
const AI_RATE_LIMIT_WINDOW = 3600;

const GEMINI_INSTRUCTOR_PROMPT = <<<TXT
Είσαι βοηθός εργαστηρίου προγραμματισμού.
- Μίλα στα Ελληνικά.
- Δώσε σύντομες, πρακτικές απαντήσεις.
- Μην δίνεις έτοιμη πλήρη λύση.
- Δώσε hints, βήματα, και μικρά παραδείγματα.
- Αν ο φοιτητής κολλήσει, πρότεινε έλεγχο edge cases.
- Αν ο φοιτητής ζητήσει να δώσεις πλήρη κώδικα ή τμήματα κώδικα αρνήσου ευγενικά.
TXT;

/**
 * --------------- HELPERS ---------------
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

// ==================== API HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true) ?? [];
    $action = $data['action'] ?? '';

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

        echo json_encode([
            'status' => 'ok',
            'user' => $_SESSION['user']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'google_logout') {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        echo json_encode(['status' => 'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'whoami') {
        echo json_encode([
            'status' => 'ok',
            'logged_in' => isLoggedIn(),
            'user' => currentUser()
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
        $userId = $user['sub'];

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
        $prompt = trim($data['prompt'] ?? '');
        $model  = trim($data['model'] ?? 'gemini-2.5-flash');
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

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => GEMINI_INSTRUCTOR_PROMPT],
                        ['text' => "\n\n---\nΟ φοιτητής ρωτά:\n" . $prompt]
                    ]
                ]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'x-goog-api-key: ' . $apiKey
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Σφάλμα cURL: ' . $err,
                'rate_limit' => $rate
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $json = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'Σφάλμα από Gemini API.',
                'details' => $json ?? $response,
                'rate_limit' => $rate
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $text = '';
        if (isset($json['candidates'][0]['content']['parts'])) {
            foreach ($json['candidates'][0]['content']['parts'] as $p) {
                $text .= $p['text'] ?? '';
            }
        }

        echo json_encode([
            'status' => 'ok',
            'text'   => $text,
            'rate_limit' => $rate
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
  <title>Python Editor + Video</title>
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
    html, body { min-height: 100%; }
    body {
      margin: 0;
      font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
      color: var(--text);
    }

    *::-webkit-scrollbar { width: 10px; height: 10px; }
    *::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.18); border-radius: 999px; }
    *::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,0.28); }

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

    .card,
    .editor-wrapper,
    .result-wrapper,
    .video-card,
    .hint-card {
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

    .status-pill,
    .test-status {
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
    .hint-card {
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

    .editor-wrapper {
      border-radius: 22px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01)),
        #0b1220;
      overflow: hidden;
      border: 1px solid rgba(148,163,184,.12);
      transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .editor-wrapper:focus-within {
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
      height: 430px;
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

    .video-frame {
      width: 100%;
      max-width: 900px;
      margin: 0 auto;
      background: #000;
      border-radius: 16px;
      overflow: hidden;
    }

    .video-frame video {
      width: 100%;
      height: auto;
      display: block;
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
      <div class="stack" id="editor-panel">
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

            <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
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

      <div class="panel-resizer" id="panel-resizer" aria-label="Resize panels" role="separator"></div>

      <div class="stack" id="video-panel">
        <div class="video-card">
          <div class="card-header-line">
            <div class="card-title-block">
              <div class="card-title">
                <span class="card-title-icon">🎥</span>
                <span>Tutorial Video</span>
              </div>
              <div class="card-sub">Παρακολούθησε το tutorial πριν ή κατά τη διάρκεια της υλοποίησης</div>
            </div>
          </div>

          <p>
            Παρακολούθησε το παρακάτω βίντεο πριν ξεκινήσεις να γράφεις Python κώδικα.
          </p>

          <div class="video-frame">
            <video controls preload="metadata">
              <source src="monotonic_stack.mp4" type="video/mp4">
              Ο browser σου δεν υποστηρίζει video.
            </video>
          </div>
        </div>

        <div class="hint-card">
          <div class="card-header-line">
            <div class="card-title-block">
              <div class="card-title">
                <span class="card-title-icon">ℹ️</span>
                <span>Σημείωση</span>
              </div>
            </div>
          </div>
          <p class="hint-box-text">
            Μπορείς να κάνεις pause/rewind στο βίντεο όσο υλοποιείς τον κώδικά σου στον editor.
          </p>
        </div>
      </div>
    </section>
  </div>

  <div id="toast-container" class="toast-container"></div>

  <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min/vs/loader.js"></script>
  <script src="https://cdn.jsdelivr.net/pyodide/v0.29.3/full/pyodide.js"></script>

  <script>
    let events = [];
    let runCount = 0;
    let totalEvents = 0;
    let isDirty = false;

    let timerRunning = false;
    let timerInterval = null;
    let timerStartTime = null;
    let elapsedMs = 0;

    let editorInstance = null;
    let pyodide = null;
    let pyodideReady = false;

    let videoTransitionLock = false;

    const STORAGE_KEY = 'python.video.ui.v1';
    const DRAFT_KEY = 'python.video.draft.v1';
    const PANEL_SPLIT_KEY = 'python.video.panel.split.v1';
    const VIDEO_HIDDEN_KEY = 'python.video.hidden.v1';

    const charCountSpan   = document.getElementById('char-count');
    const lastRunStatus   = document.getElementById('last-run-status');
    const timerSpan       = document.getElementById('timer');
    const statusDot       = document.getElementById('status-dot');
    const statusText      = document.getElementById('global-status-text');
    const resultBody      = document.getElementById('result-body');
    const testSummary     = document.getElementById('test-summary');
    const resultHeaderTitle = document.getElementById('result-header-title');
    const headerTimer = document.getElementById('header-timer');
    const headerTimerValue = document.getElementById('header-timer-value');
    const fullscreenBtn = document.getElementById('fullscreen-btn');

    const mainGrid = document.getElementById('main-grid');
    const panelResizer = document.getElementById('panel-resizer');
    const editorPanel = document.getElementById('editor-panel');
    const videoPanel = document.getElementById('video-panel');
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
    }

    function escapeHtml(str) {
      return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
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

    function updateDirtyState() {}

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

    function saveUIState() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({}));
      } catch {}
    }

    function applyPanelSplit(leftPercent) {
      const safe = Math.max(25, Math.min(75, leftPercent));
      mainGrid.style.gridTemplateColumns = `${safe}% 18px ${100 - safe}%`;
      try {
        localStorage.setItem(PANEL_SPLIT_KEY, String(safe));
      } catch {}
    }

    function loadPanelSplit() {
      try {
        const saved = parseFloat(localStorage.getItem(PANEL_SPLIT_KEY));
        if (!Number.isNaN(saved)) {
          applyPanelSplit(saved);
        }
      } catch {}
    }

    function updateToggleVideoButton() {
      if (!toggleVideoBtn) return;

      const hidden = mainGrid.classList.contains('video-hidden');

      toggleVideoBtn.innerHTML = hidden
        ? '<span class="icon">👁</span> Show Video'
        : '<span class="icon">👁</span> Hide Video';
    }

    function hideVideoPanel() {
      if (videoTransitionLock || mainGrid.classList.contains('video-hidden')) return;
      videoTransitionLock = true;

      requestAnimationFrame(() => {
        mainGrid.classList.add('video-hidden');
        updateToggleVideoButton();
      });

      try {
        localStorage.setItem(VIDEO_HIDDEN_KEY, '1');
      } catch {}

      setTimeout(() => {
        videoTransitionLock = false;
      }, 340);
    }

    function showVideoPanel() {
      if (videoTransitionLock || !mainGrid.classList.contains('video-hidden')) return;
      videoTransitionLock = true;

      requestAnimationFrame(() => {
        mainGrid.classList.remove('video-hidden');
        updateToggleVideoButton();
      });

      try {
        localStorage.setItem(VIDEO_HIDDEN_KEY, '0');
      } catch {}

      setTimeout(() => {
        videoTransitionLock = false;
      }, 340);
    }

    function loadVideoPanelVisibility() {
      try {
        const hidden = localStorage.getItem(VIDEO_HIDDEN_KEY) === '1';
        if (hidden) {
          mainGrid.classList.add('video-hidden');
        } else {
          mainGrid.classList.remove('video-hidden');
        }
      } catch {
        mainGrid.classList.remove('video-hidden');
      }

      updateToggleVideoButton();
    }

    function initPanelResizer() {
      if (!panelResizer || !mainGrid || !editorPanel || !videoPanel) return;

      let isDragging = false;

      const onMove = (clientX) => {
        if (!isDragging) return;
        const rect = mainGrid.getBoundingClientRect();
        const offset = clientX - rect.left;
        const percent = (offset / rect.width) * 100;
        applyPanelSplit(percent);
      };

      const onMouseMove = (e) => onMove(e.clientX);
      const onTouchMove = (e) => {
        if (e.touches && e.touches[0]) {
          onMove(e.touches[0].clientX);
        }
      };

      const stopDrag = () => {
        if (!isDragging) return;
        isDragging = false;
        panelResizer.classList.remove('is-dragging');
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        window.removeEventListener('mousemove', onMouseMove);
        window.removeEventListener('mouseup', stopDrag);
        window.removeEventListener('touchmove', onTouchMove);
        window.removeEventListener('touchend', stopDrag);
      };

      const startDrag = (e) => {
        if (window.innerWidth <= 1080 || mainGrid.classList.contains('video-hidden')) return;
        isDragging = true;
        panelResizer.classList.add('is-dragging');
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        window.addEventListener('mousemove', onMouseMove);
        window.addEventListener('mouseup', stopDrag);
        window.addEventListener('touchmove', onTouchMove, { passive: true });
        window.addEventListener('touchend', stopDrag);
        if (e.type === 'mousedown') {
          e.preventDefault();
        }
      };

      panelResizer.addEventListener('mousedown', startDrag);
      panelResizer.addEventListener('touchstart', startDrag, { passive: true });
    }

    function formatTimer(ms) {
      const totalSec = Math.floor(ms / 1000);
      const min = Math.floor(totalSec / 60);
      const sec = totalSec % 60;
      return String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    }

    function updateTimerDisplay() {
      let ms = elapsedMs;
      if (timerRunning && timerStartTime !== null) {
        ms = elapsedMs + (Date.now() - timerStartTime);
      }

      const sec = Math.floor(ms / 1000);
      timerSpan.textContent = sec + "s";
      headerTimerValue.textContent = formatTimer(ms);

      if (timerRunning) {
        headerTimer.classList.add('is-running');
      } else {
        headerTimer.classList.remove('is-running');
      }
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

    async function runCode() {
      const runBtn = document.getElementById('run-btn');
      const runnerResult = document.getElementById('python-runner-result');

      runnerResult?.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });

      recordEvent('run_click');
      runCount++;
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

          requestAnimationFrame(() => {
            runnerResult?.scrollIntoView({
              behavior: 'smooth',
              block: 'start'
            });
          });
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

    function sendLogs() {
      const submitBtn = document.getElementById('submit-btn');

      const currentTimerMs = timerRunning && timerStartTime !== null
        ? elapsedMs + (Date.now() - timerStartTime)
        : elapsedMs;

      const payload = {
        action: 'save_logs',
        user_id: 'anonymous',
        task_id: 'python_editor',
        language: 'python',
        code: getEditorValue(),
        events: events,
        timer_ms: currentTimerMs
      };

      setButtonLoading(
        submitBtn,
        true,
        '<span class="icon">⏳</span> Saving...',
        '<span class="icon">💾</span> Υποβολή &amp; Αποθήκευση Log'
      );

      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'ok') {
          showToast('✅ Τα δεδομένα αποθηκεύτηκαν στο logs.json', 'success');
          events = [];
          totalEvents = 0;
          isDirty = false;
        } else {
          showToast('⚠️ Σφάλμα κατά την αποθήκευση δεδομένων', 'error');
        }
      })
      .catch(err => {
        console.error('Σφάλμα:', err);
        showToast('⚠️ Σφάλμα σύνδεσης με τον server', 'error');
      })
      .finally(() => {
        setButtonLoading(
          submitBtn,
          false,
          '',
          '<span class="icon">💾</span> Υποβολή &amp; Αποθήκευση Log'
        );
      });
    }

    document.getElementById('start-timer-btn').addEventListener('click', startTimer);
    document.getElementById('stop-timer-btn').addEventListener('click', stopTimer);
    document.getElementById('run-btn').addEventListener('click', runCode);
    document.getElementById('submit-btn').addEventListener('click', () => {
      recordEvent('submit_click');
      sendLogs();
    });

    document.getElementById('reset-btn').addEventListener('click', () => {
      recordEvent('reset_click');
      setEditorValue(defaultPythonCode);
      isDirty = true;
      saveDraftFromValue(defaultPythonCode);
      showResultMessage("Ο κώδικας καθαρίστηκε. Μπορείς να ξεκινήσεις από την αρχή.");
      setStatusNeutral("Περιμένει νέο κώδικα.");
      lastRunStatus.textContent = "Καμία εκτέλεση ακόμη";
      showToast('Ο editor καθαρίστηκε.', 'info');
    });

    document.getElementById('fullscreen-btn').addEventListener('click', async () => {
      try {
        if (!document.fullscreenElement) {
          await document.documentElement.requestFullscreen();
        } else {
          await document.exitFullscreen();
        }
      } catch (err) {
        console.error('Fullscreen error:', err);
        showToast('⚠️ Δεν ήταν δυνατό το full screen', 'error');
      }
    });

    document.addEventListener('fullscreenchange', () => {
      fullscreenBtn.innerHTML = document.fullscreenElement
        ? '<span class="icon">⛶</span> Exit full screen'
        : '<span class="icon">⛶</span> Full screen';
    });

    toggleVideoBtn.addEventListener('click', () => {
      if (mainGrid.classList.contains('video-hidden')) {
        showVideoPanel();
      } else {
        hideVideoPanel();
      }
    });

    window.addEventListener('keydown', (e) => {
      const isMod = e.ctrlKey || e.metaKey;

      if (isMod && !e.shiftKey && e.key === 'Enter') {
        e.preventDefault();
        runCode();
      }

      if (isMod && e.key.toLowerCase() === 's') {
        e.preventDefault();
        sendLogs();
      }
    });

    window.addEventListener('beforeunload', saveUIState);

    window.addEventListener('load', async () => {
      isDirty = false;
      setStatusNeutral("Έτοιμο για εκτέλεση");
      updateTimerDisplay();
      await initMonaco();
      resultHeaderTitle.textContent = 'Python Runner';
      loadPanelSplit();
      initPanelResizer();
      loadVideoPanelVisibility();
      showResultMessage("Πάτησε <strong>Run</strong> για να τρέξεις γενικό Python script με υποστήριξη για <code>print()</code>.");
    });
  </script>
</body>
</html>