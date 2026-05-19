<?php
session_start();

/**
 * ADMIN CONFIG
 */
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = '123456';
const CHAT_HISTORY_DIR = __DIR__ . '/chat_history';

$host    = 'localhost';
$db      = 'diploma';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function escape(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonReadFileSafe(string $path, $default = []) {
    if (!file_exists($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false) return $default;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : $default;
}

function getParticipantChatFiles(string $participantId): array {
    $safeParticipantId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $participantId);
    $pattern = CHAT_HISTORY_DIR . '/' . $safeParticipantId . '_*.json';
    $files = glob($pattern) ?: [];

    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });

    return $files;
}

function summarizeChatFile(string $filePath): array {
    $messages = jsonReadFileSafe($filePath, []);
    $count = is_array($messages) ? count($messages) : 0;

    $firstAt = null;
    $lastAt = null;

    if ($count > 0) {
        $firstAt = $messages[0]['timestamp'] ?? null;
        $lastAt = $messages[$count - 1]['timestamp'] ?? null;
    }

    return [
        'file' => basename($filePath),
        'path' => $filePath,
        'messages' => $messages,
        'count' => $count,
        'first_at' => $firstAt,
        'last_at' => $lastAt,
        'modified_at' => date('Y-m-d H:i:s', filemtime($filePath)),
    ];
}

function renderConversationPanel(array $chat): string {
    ob_start();
    ?>
    <div class="inline-panel-header">
      <div>
        <strong>Conversation</strong>
        <div class="inline-panel-subtitle">Session: <?= escape($chat['file']) ?></div>
      </div>
    </div>

    <div class="detail-grid conversation-summary-grid" style="margin-bottom: 12px;">
      <div class="detail-box">
        <div class="detail-label">Αρχείο</div>
        <div class="detail-value"><?= escape($chat['file']) ?></div>
      </div>
      <div class="detail-box">
        <div class="detail-label">Messages</div>
        <div class="detail-value"><?= (int)$chat['count'] ?></div>
      </div>
      <div class="detail-box">
        <div class="detail-label">First</div>
        <div class="detail-value"><?= escape($chat['first_at'] ?: '-') ?></div>
      </div>
      <div class="detail-box">
        <div class="detail-label">Last</div>
        <div class="detail-value"><?= escape($chat['last_at'] ?: '-') ?></div>
      </div>
    </div>

    <?php if (empty($chat['messages'])): ?>
      <div class="empty-state">Το αρχείο δεν περιέχει μηνύματα.</div>
    <?php else: ?>
      <div class="conversation-box">
        <?php foreach ($chat['messages'] as $msg): ?>
          <?php
            $role = $msg['role'] ?? 'unknown';
            $text = $msg['text'] ?? '';
            $timestamp = $msg['timestamp'] ?? '-';
            $participantId = $msg['participant_id'] ?? '-';
            $googleSub = $msg['google_sub'] ?? '-';
            $chatSessionId = $msg['chat_session_id'] ?? '-';

            $normalizedRole = strtolower((string)$role);
            $isUser = in_array($normalizedRole, ['user', 'question', 'participant'], true);
            $bubbleClass = $isUser ? 'message-left' : 'message-right';
          ?>
          <div class="message-row <?= $bubbleClass ?>">
            <div class="message <?= escape($role) ?>">
              <div class="message-meta">
                <strong>Role:</strong> <?= escape($role) ?> |
                <strong>Time:</strong> <?= escape($timestamp) ?><br>
                <strong>Participant:</strong> <?= escape($participantId) ?> |
                <strong>Google Sub:</strong> <?= escape($googleSub) ?><br>
                <strong>Session:</strong> <?= escape($chatSessionId) ?>
              </div>
              <?= nl2br(escape($text)) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function renderSessionsTable(string $participantId, array $files): string {
    global $sessionConversationHtml;

    ob_start();
    ?>
    <div class="inline-panel-header">
      <div>
        <strong>Sessions</strong>
        <div class="inline-panel-subtitle">Διαθέσιμα session files για participant: <?= escape($participantId) ?></div>
      </div>
    </div>

    <?php if (empty($files)): ?>
      <div class="empty-state">Δεν βρέθηκαν αρχεία συνομιλίας για αυτόν τον participant.</div>
    <?php else: ?>
      <div class="table-wrap inner-table-wrap">
        <table class="data-table inner-table">
          <thead>
            <tr>
              <th>Session File</th>
              <th>Messages</th>
              <th>First</th>
              <th>Last</th>
              <th>Modified</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($files as $filePath): ?>
              <?php
                $fileInfo = summarizeChatFile($filePath);
                $base = basename($filePath);
                $idKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $participantId . '__' . $base);
                $detailId = 'session-details-' . $idKey;
                $conversationHtml = $sessionConversationHtml[$participantId . '||' . $base] ?? '';
              ?>
              <tr class="clickable-row session-row" data-detail-id="<?= escape($detailId) ?>">
                <td><span class="table-id"><?= escape($base) ?></span></td>
                <td><span class="badge session"><?= (int)$fileInfo['count'] ?></span></td>
                <td><?= escape($fileInfo['first_at'] ?: '-') ?></td>
                <td><?= escape($fileInfo['last_at'] ?: '-') ?></td>
                <td><?= escape($fileInfo['modified_at'] ?: '-') ?></td>
              </tr>
              <tr class="session-details-row" id="<?= escape($detailId) ?>">
                <td colspan="5">
                  <div class="details-outer">
                    <div class="inline-details-panel details-inner">
                      <?= $conversationHtml ?>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * LOGIN / LOGOUT
 */
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: admin_dashboard.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin_dashboard.php');
        exit;
    } else {
        $loginError = 'Λάθος στοιχεία admin.';
    }
}

if (!isAdminLoggedIn()):
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
        --bg: #07101f;
        --bg-2: #050b16;
        --surface: rgba(13, 20, 38, 0.84);
        --surface-2: rgba(16, 24, 44, 0.96);
        --text: #e8eefb;
        --text-soft: #bfd0ef;
        --text-muted: #8393b3;
        --accent: #5b8cff;
        --accent-2: #3b82f6;
        --danger: #ef4444;
        --border: rgba(148,163,184,0.14);
        --border-strong: rgba(148,163,184,0.24);
        --shadow-md: 0 20px 48px rgba(0,0,0,0.24);
        --shadow-lg: 0 30px 80px rgba(0,0,0,0.34);
        --radius-lg: 24px;
        --radius-md: 16px;
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        width: 100%;
        min-height: 100%;
        margin: 0;
    }

    body {
        font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background:
            radial-gradient(circle at 12% 18%, rgba(59,130,246,0.16), transparent 28%),
            radial-gradient(circle at 88% 10%, rgba(139,92,246,0.12), transparent 24%),
            linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.2rem;
        overflow-x: hidden;
    }

    .shell {
        width: 100%;
        max-width: 460px;
    }

    .card {
        position: relative;
        background:
            radial-gradient(circle at top left, rgba(91,140,255,0.16), transparent 32%),
            radial-gradient(circle at bottom right, rgba(34,197,94,0.08), transparent 28%),
            var(--surface);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-lg);
        border-radius: var(--radius-lg);
        padding: 1.35rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        overflow: hidden;
    }

    .card::before {
        content: "";
        position: absolute;
        inset: 0;
        pointer-events: none;
        background: linear-gradient(
            180deg,
            rgba(255,255,255,0.06),
            transparent 34%
        );
    }

    .title {
        position: relative;
        font-size: 1.08rem;
        font-weight: 900;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        letter-spacing: -0.02em;
    }

    .title-badge {
        width: 38px;
        height: 38px;
        border-radius: 14px;
        background: rgba(91,140,255,0.14);
        border: 1px solid rgba(91,140,255,0.24);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        box-shadow: 0 10px 26px rgba(59,130,246,0.14);
        flex: 0 0 auto;
    }

    .subtitle {
        position: relative;
        margin: -0.25rem 0 0;
        font-size: 0.86rem;
        color: var(--text-soft);
        line-height: 1.6;
    }

    .error-box {
        position: relative;
        padding: 0.8rem 0.95rem;
        border-radius: 14px;
        border: 1px solid rgba(248,113,113,0.32);
        background: rgba(127,29,29,0.32);
        color: #fee2e2;
        font-size: 0.82rem;
        line-height: 1.5;
    }

    form {
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
        margin-top: 0.2rem;
    }

    .field {
        display: flex;
        flex-direction: column;
        gap: 0.42rem;
        font-size: 0.84rem;
    }

    .field label {
        color: var(--text);
        font-weight: 800;
        font-size: 0.8rem;
    }

    input[type="text"],
    input[type="password"] {
        width: 100%;
        height: 44px;
        border-radius: 13px;
        border: 1px solid var(--border-strong);
        padding: 0 0.9rem;
        font-size: 0.9rem;
        color: var(--text);
        background:
            linear-gradient(180deg, rgba(255,255,255,0.07), rgba(255,255,255,0.03)),
            rgba(7, 11, 22, 0.76);
        outline: none;
        font-family: inherit;
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,0.05),
            0 10px 28px rgba(0,0,0,0.16);
        transition:
            border-color 180ms ease,
            box-shadow 180ms ease,
            background 180ms ease;
    }

    input[type="text"]:focus,
    input[type="password"]:focus {
        border-color: rgba(91,140,255,0.55);
        background:
            linear-gradient(180deg, rgba(255,255,255,0.09), rgba(255,255,255,0.04)),
            rgba(10, 18, 34, 0.88);
        box-shadow:
            0 0 0 4px rgba(91,140,255,0.12),
            0 14px 34px rgba(59,130,246,0.16);
    }

    .btn {
        margin-top: 0.35rem;
        width: 100%;
        height: 46px;
        border-radius: 13px;
        border: 1px solid rgba(91,140,255,0.28);
        background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
        color: #fff;
        padding: 0 1.1rem;
        font-size: 0.88rem;
        font-weight: 900;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        cursor: pointer;
        box-shadow: 0 14px 30px rgba(59,130,246,0.28);
        transition:
            transform 180ms ease,
            box-shadow 180ms ease,
            opacity 180ms ease;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 18px 38px rgba(59,130,246,0.36);
    }

    .btn:active {
        transform: translateY(0);
    }

    @media (max-width: 520px) {
        body {
            align-items: flex-start;
            padding: 1rem;
        }

        .shell {
            margin-top: 1rem;
        }

        .card {
            padding: 1rem;
            border-radius: 20px;
        }
    }
  </style>
</head>
<body>
  <div class="shell">
    <section class="card">
      <?php if ($loginError !== ''): ?><div class="error-box"><?= escape($loginError) ?></div><?php endif; ?>
      <div class="title"><span class="title-badge">🛡️</span><span>Είσοδος Διαχειριστή</span></div>
      <p class="subtitle">Συνδέσου για να δεις participants και συνομιλίες.</p>
      <form method="post">
        <input type="hidden" name="admin_login" value="1">
        <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" required></div>
        <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required></div>
        <button class="btn" type="submit"><span>→</span>Σύνδεση στο Dashboard</button>
      </form>
    </section>
  </div>
</body>
</html>
<?php
exit;
endif;

/**
 * FILTERS
 */
$selectedType = trim($_GET['type'] ?? 'ai');
$allowedTypes = ['ai', 'video', 'all'];

if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = 'ai';
}

/**
 * DB LOAD
 */
try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    if ($selectedType === 'all') {
        $stmt = $pdo->query("SELECT id, participant_id, name, study_year, experience, assistance_type, created_at FROM Diploma ORDER BY created_at DESC");
        $participants = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT id, participant_id, name, study_year, experience, assistance_type, created_at FROM Diploma WHERE assistance_type = :assistance_type ORDER BY created_at DESC");
        $stmt->execute([':assistance_type' => $selectedType]);
        $participants = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    die('Σφάλμα σύνδεσης με τη βάση: ' . escape($e->getMessage()));
}

/**
 * PREBUILD CONVERSATIONS / SESSIONS
 */
$sessionConversationHtml = [];
foreach ($participants as $p) {
    $pid = (string)$p['participant_id'];
    $files = getParticipantChatFiles($pid);

    foreach ($files as $filePath) {
        $chat = summarizeChatFile($filePath);
        $base = basename($filePath);
        $sessionConversationHtml[$pid . '||' . $base] = renderConversationPanel($chat);
    }
}

$participantSessionsHtml = [];
foreach ($participants as $p) {
    $pid = (string)$p['participant_id'];
    $files = getParticipantChatFiles($pid);
    $participantSessionsHtml[$pid] = renderSessionsTable($pid, $files);
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard – Participants</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#0b1324;--bg-2:#09101d;--sidebar:rgba(9,15,28,.96);--surface:rgba(13,20,38,.92);--surface-2:rgba(16,24,44,.98);--text:#e8eefb;--text-soft:#bfd0ef;--text-muted:#8393b3;--border:rgba(148,163,184,.14);--border-strong:rgba(148,163,184,.24);--accent-soft:rgba(91,140,255,.14);--success-soft:rgba(34,197,94,.14);--warning-soft:rgba(245,158,11,.14);--danger-soft:rgba(239,68,68,.16);--shadow-md:0 20px 48px rgba(0,0,0,.24);--radius-lg:22px;--sidebar-width:250px}*{box-sizing:border-box}html,body{margin:0;min-height:100%;height:100%}body{font-family:"Manrope",system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(circle at 12% 18%,rgba(59,130,246,.14),transparent 28%),radial-gradient(circle at 88% 10%,rgba(139,92,246,.10),transparent 24%),linear-gradient(180deg,var(--bg) 0%,var(--bg-2) 100%);color:var(--text);overflow-x:hidden;overflow-y:auto}a{color:inherit;text-decoration:none}button{font:inherit}.app-shell{display:grid;grid-template-columns:var(--sidebar-width) minmax(0,1fr);min-height:100vh;align-items:start}.sidebar{background:var(--sidebar);border-right:1px solid var(--border);padding:18px 14px;display:flex;flex-direction:column;gap:14px;min-width:0;position:sticky;top:0;height:100vh}.sidebar-title{font-size:1.08rem;font-weight:800;padding:.55rem .7rem .7rem;color:var(--text)}.sidebar-subtitle{font-size:.74rem;color:var(--text-muted);padding:0 .7rem;line-height:1.5;margin-top:-6px}.sidebar-nav{display:flex;flex-direction:column;gap:8px;margin-top:6px}.sidebar-link{height:42px;border-radius:12px;padding:0 12px;display:flex;align-items:center;gap:10px;color:var(--text-soft);border:1px solid transparent;background:transparent;font-size:.82rem;font-weight:700}.sidebar-link:hover{background:rgba(255,255,255,.04);border-color:var(--border);color:var(--text)}.sidebar-link.active{background:var(--accent-soft);border-color:rgba(91,140,255,.28);color:#fff}.sidebar-spacer{flex:1 1 auto}.sidebar-logout{height:40px;border-radius:12px;border:1px solid rgba(239,68,68,.26);background:var(--danger-soft);color:#fecaca;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700}.main-content{min-width:0;display:flex;flex-direction:column;overflow:visible}.topbar{min-height:70px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;background:rgba(8,14,28,.5);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);flex:0 0 auto;position:sticky;top:0;z-index:20}.topbar-left{display:flex;align-items:center;gap:10px;min-width:0}.mobile-menu-btn{display:none;height:38px;padding:0 12px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text);align-items:center;gap:8px;font-size:.78rem;font-weight:700;cursor:pointer}.topbar-title{font-size:1rem;font-weight:800;white-space:nowrap}.topbar-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end}.topbar-btn{height:36px;padding:0 12px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--text);display:inline-flex;align-items:center;gap:8px;font-size:.78rem;font-weight:700}.topbar-btn.active-filter{background:linear-gradient(180deg,#5b8cff 0%,#3b82f6 100%);border-color:rgba(91,140,255,.28);color:#fff;box-shadow:0 10px 24px rgba(59,130,246,.22)}.page-content{padding:16px 18px 18px;overflow:visible;display:flex;flex-direction:column;gap:16px;min-width:0}.section-card{background:radial-gradient(circle at top left,rgba(91,140,255,.08),transparent 26%),var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:12px;overflow:visible}.section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}.section-title{font-size:.95rem;font-weight:800;color:var(--text)}.section-subtitle{font-size:.76rem;color:var(--text-muted);line-height:1.5}.table-wrap{width:100%;overflow-x:auto;overflow-y:visible;border:1px solid var(--border);border-radius:14px;background:rgba(255,255,255,.02)}.data-table{width:100%;min-width:860px;border-collapse:collapse}.data-table thead th{background:var(--surface-2);color:var(--text-soft);text-align:left;padding:12px 14px;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--border-strong);white-space:nowrap;position:sticky;top:0;z-index:1}.data-table tbody td{padding:12px 14px;font-size:.8rem;border-bottom:1px solid rgba(148,163,184,.08);vertical-align:middle;white-space:nowrap}.data-table tbody tr{transition:background .18s ease}.data-table tbody tr:hover{background:rgba(255,255,255,.04)}.data-table tbody tr.active-row{background:rgba(91,140,255,.10)}.clickable-row{cursor:pointer}.table-id{font-weight:800;color:var(--text)}.badge{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:0 8px;border-radius:999px;font-size:.66rem;font-weight:800;letter-spacing:.03em;border:1px solid transparent;white-space:nowrap}.badge.ai{background:var(--accent-soft);color:#bfdbfe;border-color:rgba(59,130,246,.22)}.badge.video{background:var(--success-soft);color:#bbf7d0;border-color:rgba(34,197,94,.22)}.badge.session{background:var(--warning-soft);color:#fde68a;border-color:rgba(245,158,11,.20)}.detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.detail-box{border:1px solid var(--border);background:rgba(255,255,255,.03);border-radius:14px;padding:12px;min-width:0}.detail-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:6px}.detail-value{font-size:.8rem;color:var(--text);line-height:1.45;word-break:break-word}.conversation-box{display:flex;flex-direction:column;gap:10px;overflow:visible;padding:6px 8px 0 8px}.message-row{display:flex;width:100%}.message-row.message-left{justify-content:flex-start}.message-row.message-right{justify-content:flex-end}.message{width:fit-content;max-width:min(78%,820px);border-radius:16px;padding:10px 12px;border:1px solid var(--border);background:rgba(255,255,255,.04);line-height:1.5;font-size:.74rem;white-space:pre-wrap;word-break:break-word;box-shadow:0 8px 20px rgba(0,0,0,.12)}.message-row.message-left .message{background:rgba(255,255,255,.04);border-color:rgba(148,163,184,.18);border-top-left-radius:6px}.message-row.message-right .message{background:rgba(91,140,255,.14);border-color:rgba(91,140,255,.24);border-top-right-radius:6px}.message-meta{font-size:.62rem;color:var(--text-muted);margin-bottom:6px;line-height:1.35}.empty-state{border:1px dashed rgba(148,163,184,.22);border-radius:14px;padding:14px;color:var(--text-soft);font-size:.8rem;line-height:1.6;background:rgba(255,255,255,.02)}.participant-details-row,.session-details-row{display:table-row}.participant-details-row td,.session-details-row td{padding:0!important;background:transparent;white-space:normal;border-bottom:0!important}.details-outer{display:grid;grid-template-rows:0fr;opacity:0;transition:grid-template-rows .32s ease,opacity .24s ease;margin:0;padding:0 10px}.details-inner{overflow:hidden;min-height:0;margin:0}.participant-details-row.open .details-outer,.session-details-row.open .details-outer{grid-template-rows:1fr;opacity:1}.inline-details-panel{padding:12px 14px 0 14px;border-top:0;background:transparent;transform:translateY(-4px);transition:transform .32s ease}.participant-details-row.open .inline-details-panel,.session-details-row.open .inline-details-panel{transform:translateY(0)}.inline-panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}.inline-panel-subtitle{font-size:.75rem;color:var(--text-muted);margin-top:4px}.inner-table{min-width:100%}.conversation-summary-grid{padding:0 8px}*::-webkit-scrollbar{width:10px;height:10px}*::-webkit-scrollbar-thumb{background:rgba(148,163,184,.18);border-radius:999px}*::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.28)}@media(max-width:1024px){.app-shell{grid-template-columns:1fr}.sidebar{display:none}.sidebar.is-open{display:flex;position:fixed;inset:0 auto 0 0;width:var(--sidebar-width);z-index:1000;box-shadow:0 20px 48px rgba(0,0,0,.34)}.mobile-menu-btn{display:inline-flex}.detail-grid,.conversation-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.topbar{align-items:flex-start;flex-direction:column}.topbar-actions{width:100%;justify-content:flex-start}.detail-grid,.conversation-summary-grid{grid-template-columns:1fr}.page-content{padding:12px}.section-card{padding:12px}.data-table{min-width:720px}.message{max-width:90%}.details-outer{padding:0 6px}.inline-details-panel{padding:10px 10px 0 10px}.conversation-box{padding:6px 4px 0 4px}.conversation-summary-grid{padding:0 4px}}
  </style>
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-title">Admin Dashboard</div>
      <div class="sidebar-subtitle">Participants, sessions και συνομιλίες.</div>
      <nav class="sidebar-nav">
        <a class="sidebar-link active" href="admin_dashboard.php">Participants</a>
        <a class="sidebar-link" href="admin_se_score.php">SE score</a>
        <a class="sidebar-link" href="admin_objective_success.php">Objective Success</a>
        <a class="sidebar-link" href="admin_results.php">Results</a>
      </nav>
      <div class="sidebar-spacer"></div>
      <a href="?logout=1" class="sidebar-logout">Αποσύνδεση</a>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div class="topbar-left">
          <button class="mobile-menu-btn" type="button" onclick="toggleSidebar()">☰ Menu</button>
          <div class="topbar-title">Participants Dashboard</div>
        </div>
        <div class="topbar-actions">
          <a class="topbar-btn <?= $selectedType === 'ai' ? 'active-filter' : '' ?>" href="?type=ai">AI</a>
          <a class="topbar-btn <?= $selectedType === 'video' ? 'active-filter' : '' ?>" href="?type=video">Video</a>
          <a class="topbar-btn <?= $selectedType === 'all' ? 'active-filter' : '' ?>" href="?type=all">Όλοι</a>
        </div>
      </header>

      <div class="page-content">
        <section class="section-card" id="participants-table">
          <div class="section-head">
            <div class="section-title-wrap">
              <div class="section-title">Participants Table</div>
              <div class="section-subtitle">Προβολή participants σε μορφή πίνακα.</div>
            </div>
            <div class="section-tools"><span class="section-subtitle">Σύνολο λίστας: <?= count($participants) ?></span></div>
          </div>

          <div class="table-wrap">
            <?php if (empty($participants)): ?>
              <div class="empty-state" style="margin: 12px;">Δεν βρέθηκαν participants για αυτό το filter.</div>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr><th>Participant ID</th><th>Τύπος</th><th>Όνομα</th><th>Έτος</th><th>Εμπειρία</th><th>Created</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($participants as $p): ?>
                    <?php
                      $pid = (string)$p['participant_id'];
                      $ptype = (string)($p['assistance_type'] ?? '');
                      $participantDetailId = 'participant-details-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pid);
                    ?>
                    <tr class="clickable-row participant-row" data-detail-id="<?= escape($participantDetailId) ?>">
                      <td><span class="table-id"><?= escape($pid) ?></span></td>
                      <td><span class="badge <?= $ptype === 'video' ? 'video' : 'ai' ?>"><?= strtoupper(escape($ptype ?: '-')) ?></span></td>
                      <td><?= escape($p['name'] ?: '-') ?></td>
                      <td><?= escape($p['study_year'] ?: '-') ?></td>
                      <td><?= escape($p['experience'] ?: '-') ?></td>
                      <td><?= escape($p['created_at'] ?: '-') ?></td>
                    </tr>
                    <tr class="participant-details-row" id="<?= escape($participantDetailId) ?>">
                      <td colspan="6">
                        <div class="details-outer">
                          <div class="inline-details-panel details-inner">
                            <?= $participantSessionsHtml[$pid] ?? '' ?>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
    function toggleSidebar(){const sidebar=document.getElementById('sidebar');if(sidebar){sidebar.classList.toggle('is-open')}}
    function openRow(detailsRow, triggerRow){if(!detailsRow)return;detailsRow.classList.add('open');if(triggerRow){triggerRow.classList.add('active-row')}}
    function closeAllParticipantPanels(){document.querySelectorAll('.participant-details-row').forEach(row=>row.classList.remove('open'));document.querySelectorAll('.participant-row').forEach(row=>row.classList.remove('active-row'));document.querySelectorAll('.session-details-row').forEach(row=>row.classList.remove('open'));document.querySelectorAll('.session-row').forEach(row=>row.classList.remove('active-row'))}
    function closeAllSessionPanels(container){if(!container)return;container.querySelectorAll('.session-details-row').forEach(row=>row.classList.remove('open'));container.querySelectorAll('.session-row').forEach(row=>row.classList.remove('active-row'))}
    document.addEventListener('click',function(e){const sidebar=document.getElementById('sidebar');const btn=document.querySelector('.mobile-menu-btn');if(sidebar&&window.innerWidth<=1024&&sidebar.classList.contains('is-open')){const clickedInsideSidebar=sidebar.contains(e.target);const clickedButton=btn&&btn.contains(e.target);if(!clickedInsideSidebar&&!clickedButton){sidebar.classList.remove('is-open')}}const sessionRow=e.target.closest('.session-row');if(sessionRow){e.stopPropagation();const detailId=sessionRow.dataset.detailId;const detailsRow=document.getElementById(detailId);if(!detailsRow)return;const container=sessionRow.closest('tbody');const isOpen=detailsRow.classList.contains('open');closeAllSessionPanels(container);if(!isOpen){openRow(detailsRow,sessionRow)}return}const participantRow=e.target.closest('.participant-row');if(participantRow){const detailId=participantRow.dataset.detailId;const detailsRow=document.getElementById(detailId);if(!detailsRow)return;const isOpen=detailsRow.classList.contains('open');closeAllParticipantPanels();if(!isOpen){openRow(detailsRow,participantRow)}}});
  </script>
</body>
</html>