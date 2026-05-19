<?php
session_start();

/**
 * ADMIN CONFIG
 */
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = '123456';

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

function formatBool($value): string {
    return ((int)$value === 1) ? 'YES' : 'NO';
}

function scoreClass($score): string {
    $score = (float)$score;

    if ($score >= 80) return 'score-high';
    if ($score >= 50) return 'score-mid';
    return 'score-low';
}

/**
 * LOGOUT / AUTH CHECK
 */
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: admin_dashboard.php');
    exit;
}

if (!isAdminLoggedIn()) {
    header('Location: admin_dashboard.php');
    exit;
}

/**
 * FILTERS
 */
$selectedType = trim($_GET['type'] ?? 'all');
$allowedTypes = ['ai', 'video', 'all'];

if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = 'all';
}

/**
 * DB LOAD
 */
try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    if ($selectedType === 'all') {
        $stmt = $pdo->query("
            SELECT
                id,
                user_id,
                `condition`,
                task_id,
                code_snapshot,
                visible_test_passed,
                visible_test_output,
                hidden_test_1_passed,
                hidden_test_1_output,
                hidden_test_2_passed,
                hidden_test_2_output,
                decreasing_property_passed,
                hardcoded_solution_detected,
                final_objective_success_score,
                completed_successfully,
                created_at
            FROM objective_success_logs
            ORDER BY created_at DESC
        ");
        $logs = $stmt->fetchAll();
    } else {
        $condition = $selectedType === 'ai' ? 'ai_assistance' : 'video_tutorial';

        $stmt = $pdo->prepare("
            SELECT
                id,
                user_id,
                `condition`,
                task_id,
                code_snapshot,
                visible_test_passed,
                visible_test_output,
                hidden_test_1_passed,
                hidden_test_1_output,
                hidden_test_2_passed,
                hidden_test_2_output,
                decreasing_property_passed,
                hardcoded_solution_detected,
                final_objective_success_score,
                completed_successfully,
                created_at
            FROM objective_success_logs
            WHERE `condition` = :condition
            ORDER BY created_at DESC
        ");

        $stmt->execute([
            ':condition' => $condition
        ]);

        $logs = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    die('Σφάλμα σύνδεσης με τη βάση: ' . escape($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard – Objective Success</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --bg: #0b1324;
      --bg-2: #09101d;
      --sidebar: rgba(9, 15, 28, 0.96);
      --surface: rgba(13, 20, 38, 0.92);
      --surface-2: rgba(16, 24, 44, 0.98);
      --text: #e8eefb;
      --text-soft: #bfd0ef;
      --text-muted: #8393b3;
      --border: rgba(148,163,184,0.14);
      --border-strong: rgba(148,163,184,0.24);
      --accent-soft: rgba(91,140,255,0.14);
      --success-soft: rgba(34,197,94,0.14);
      --warning-soft: rgba(245,158,11,0.14);
      --danger-soft: rgba(239,68,68,0.16);
      --shadow-md: 0 20px 48px rgba(0,0,0,0.24);
      --radius-lg: 22px;
      --sidebar-width: 250px;
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      margin: 0;
      min-height: 100%;
      height: 100%;
    }

    body {
      font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
      color: var(--text);
      overflow-x: hidden;
      overflow-y: auto;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    button {
      font: inherit;
    }

    .app-shell {
      display: grid;
      grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
      min-height: 100vh;
      align-items: start;
    }

    .sidebar {
      background: var(--sidebar);
      border-right: 1px solid var(--border);
      padding: 18px 14px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      min-width: 0;
      position: sticky;
      top: 0;
      height: 100vh;
    }

    .sidebar-title {
      font-size: 1.08rem;
      font-weight: 800;
      padding: .55rem .7rem .7rem;
      color: var(--text);
    }

    .sidebar-subtitle {
      font-size: .74rem;
      color: var(--text-muted);
      padding: 0 .7rem;
      line-height: 1.5;
      margin-top: -6px;
    }

    .sidebar-nav {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 6px;
    }

    .sidebar-link {
      height: 42px;
      border-radius: 12px;
      padding: 0 12px;
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--text-soft);
      border: 1px solid transparent;
      background: transparent;
      font-size: .82rem;
      font-weight: 700;
    }

    .sidebar-link:hover {
      background: rgba(255,255,255,.04);
      border-color: var(--border);
      color: var(--text);
    }

    .sidebar-link.active {
      background: var(--accent-soft);
      border-color: rgba(91,140,255,.28);
      color: #fff;
    }

    .sidebar-spacer {
      flex: 1 1 auto;
    }

    .sidebar-logout {
      height: 40px;
      border-radius: 12px;
      border: 1px solid rgba(239,68,68,0.26);
      background: var(--danger-soft);
      color: #fecaca;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .8rem;
      font-weight: 700;
    }

    .main-content {
      min-width: 0;
      display: flex;
      flex-direction: column;
      overflow: visible;
    }

    .topbar {
      min-height: 70px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 18px;
      background: rgba(8, 14, 28, 0.5);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      flex: 0 0 auto;
      position: sticky;
      top: 0;
      z-index: 20;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }

    .mobile-menu-btn {
      display: none;
      height: 38px;
      padding: 0 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text);
      align-items: center;
      gap: 8px;
      font-size: .78rem;
      font-weight: 700;
      cursor: pointer;
    }

    .topbar-title {
      font-size: 1rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .topbar-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      justify-content: flex-end;
    }

    .topbar-btn {
      height: 36px;
      padding: 0 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.04);
      color: var(--text);
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: .78rem;
      font-weight: 700;
    }

    .topbar-btn.active-filter {
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      border-color: rgba(91,140,255,.28);
      color: #fff;
      box-shadow: 0 10px 24px rgba(59,130,246,.22);
    }

    .page-content {
      padding: 16px 18px 18px;
      overflow: visible;
      display: flex;
      flex-direction: column;
      gap: 16px;
      min-width: 0;
    }

    .section-card {
      background:
        radial-gradient(circle at top left, rgba(91,140,255,0.08), transparent 26%),
        var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-md);
      padding: 12px;
      overflow: visible;
    }

    .section-head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .section-title {
      font-size: .95rem;
      font-weight: 800;
      color: var(--text);
    }

    .section-subtitle {
      font-size: .76rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-top: 4px;
    }

    .table-wrap {
      width: 100%;
      overflow-x: auto;
      overflow-y: visible;
      border: 1px solid var(--border);
      border-radius: 14px;
      background: rgba(255,255,255,.02);
    }

    .data-table {
      width: 100%;
      min-width: 1200px;
      border-collapse: collapse;
    }

    .data-table thead th {
      background: var(--surface-2);
      color: var(--text-soft);
      text-align: left;
      padding: 12px 14px;
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      border-bottom: 1px solid var(--border-strong);
      white-space: nowrap;
      position: sticky;
      top: 0;
      z-index: 1;
    }

    .data-table tbody td {
      padding: 12px 14px;
      font-size: .8rem;
      border-bottom: 1px solid rgba(148,163,184,.08);
      vertical-align: middle;
      white-space: nowrap;
    }

    .data-table tbody tr {
      transition: background .18s ease;
    }

    .data-table tbody tr:hover {
      background: rgba(255,255,255,.04);
    }

    .table-id {
      font-weight: 800;
      color: var(--text);
    }

    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 24px;
      padding: 0 8px;
      border-radius: 999px;
      font-size: .66rem;
      font-weight: 800;
      letter-spacing: .03em;
      border: 1px solid transparent;
      white-space: nowrap;
    }

    .badge.ai {
      background: var(--accent-soft);
      color: #bfdbfe;
      border-color: rgba(59,130,246,.22);
    }

    .badge.video {
      background: var(--success-soft);
      color: #bbf7d0;
      border-color: rgba(34,197,94,.22);
    }

    .badge.yes {
      background: var(--success-soft);
      color: #bbf7d0;
      border-color: rgba(34,197,94,.22);
    }

    .badge.no {
      background: var(--danger-soft);
      color: #fecaca;
      border-color: rgba(239,68,68,.22);
    }

    .badge.warning {
      background: var(--warning-soft);
      color: #fde68a;
      border-color: rgba(245,158,11,.22);
    }

    .score-high {
      color: #86efac;
      font-weight: 900;
    }

    .score-mid {
      color: #fde68a;
      font-weight: 900;
    }

    .score-low {
      color: #fca5a5;
      font-weight: 900;
    }

    .code-box,
    .output-box {
      max-width: 420px;
      max-height: 140px;
      overflow: auto;
      white-space: pre-wrap;
      word-break: break-word;
      font-family: "JetBrains Mono", "Fira Code", monospace;
      font-size: .72rem;
      line-height: 1.45;
      color: var(--text-soft);
      background: rgba(2,6,23,.58);
      border: 1px solid rgba(148,163,184,.12);
      border-radius: 12px;
      padding: 8px 10px;
    }

    .empty-state {
      border: 1px dashed rgba(148,163,184,.22);
      border-radius: 14px;
      padding: 14px;
      color: var(--text-soft);
      font-size: .8rem;
      line-height: 1.6;
      background: rgba(255,255,255,.02);
    }

    *::-webkit-scrollbar {
      width: 10px;
      height: 10px;
    }

    *::-webkit-scrollbar-thumb {
      background: rgba(148,163,184,0.18);
      border-radius: 999px;
    }

    *::-webkit-scrollbar-thumb:hover {
      background: rgba(148,163,184,0.28);
    }

    @media (max-width: 1024px) {
      .app-shell {
        grid-template-columns: 1fr;
      }

      .sidebar {
        display: none;
      }

      .sidebar.is-open {
        display: flex;
        position: fixed;
        inset: 0 auto 0 0;
        width: var(--sidebar-width);
        z-index: 1000;
        box-shadow: 0 20px 48px rgba(0,0,0,0.34);
      }

      .mobile-menu-btn {
        display: inline-flex;
      }
    }

    @media (max-width: 640px) {
      .topbar {
        align-items: flex-start;
        flex-direction: column;
      }

      .topbar-actions {
        width: 100%;
        justify-content: flex-start;
      }

      .page-content {
        padding: 12px;
      }

      .section-card {
        padding: 12px;
      }
    }
  </style>
</head>

<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-title">Admin Dashboard</div>
      <div class="sidebar-subtitle">Participants, SE scores, objective success και συνολικά αποτελέσματα.</div>

      <nav class="sidebar-nav">
        <a class="sidebar-link" href="admin_dashboard.php">Participants</a>
        <a class="sidebar-link" href="admin_se_score.php">SE score</a>
        <a class="sidebar-link active" href="admin_objective_success.php">Objective Success</a>
        <a class="sidebar-link" href="admin_results.php">Results</a>
      </nav>

      <div class="sidebar-spacer"></div>

      <a href="?logout=1" class="sidebar-logout">Αποσύνδεση</a>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div class="topbar-left">
          <button class="mobile-menu-btn" type="button" onclick="toggleSidebar()">☰ Menu</button>
          <div class="topbar-title">Objective Success Dashboard</div>
        </div>

        <div class="topbar-actions">
          <a class="topbar-btn <?= $selectedType === 'ai' ? 'active-filter' : '' ?>" href="?type=ai">AI</a>
          <a class="topbar-btn <?= $selectedType === 'video' ? 'active-filter' : '' ?>" href="?type=video">Video</a>
          <a class="topbar-btn <?= $selectedType === 'all' ? 'active-filter' : '' ?>" href="?type=all">Όλοι</a>
        </div>
      </header>

      <div class="page-content">
        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">Objective Success Logs</div>
              <div class="section-subtitle">
                Αντικειμενική επιτυχία υλοποίησης με βάση visible/hidden tests, decreasing property και final score.
              </div>
            </div>

            <div class="section-subtitle">
              Σύνολο logs: <?= count($logs) ?>
            </div>
          </div>

          <div class="table-wrap">
            <?php if (empty($logs)): ?>
              <div class="empty-state" style="margin: 12px;">
                Δεν βρέθηκαν objective success logs για αυτό το filter.
              </div>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>User / Participant</th>
                    <th>Condition</th>
                    <th>Task</th>
                    <th>Visible</th>
                    <th>Hidden 1</th>
                    <th>Hidden 2</th>
                    <th>Decreasing</th>
                    <th>Hardcoded</th>
                    <th>Score</th>
                    <th>Completed</th>
                    <th>Visible Output</th>
                    <th>Hidden 1 Output</th>
                    <th>Hidden 2 Output</th>
                    <th>Code Snapshot</th>
                    <th>Created</th>
                  </tr>
                </thead>

                <tbody>
                  <?php foreach ($logs as $log): ?>
                    <?php
                      $condition = (string)($log['condition'] ?? '');
                      $conditionClass = $condition === 'video_tutorial' ? 'video' : 'ai';
                      $score = (float)($log['final_objective_success_score'] ?? 0);
                    ?>
                    <tr>
                      <td><span class="table-id"><?= escape((string)$log['id']) ?></span></td>

                      <td><span class="table-id"><?= escape($log['user_id'] ?? '-') ?></span></td>

                      <td>
                        <span class="badge <?= $conditionClass ?>">
                          <?= escape($condition) ?>
                        </span>
                      </td>

                      <td><?= escape($log['task_id'] ?? '-') ?></td>

                      <td>
                        <span class="badge <?= ((int)$log['visible_test_passed'] === 1) ? 'yes' : 'no' ?>">
                          <?= formatBool($log['visible_test_passed']) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= ((int)$log['hidden_test_1_passed'] === 1) ? 'yes' : 'no' ?>">
                          <?= formatBool($log['hidden_test_1_passed']) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= ((int)$log['hidden_test_2_passed'] === 1) ? 'yes' : 'no' ?>">
                          <?= formatBool($log['hidden_test_2_passed']) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= ((int)$log['decreasing_property_passed'] === 1) ? 'yes' : 'no' ?>">
                          <?= formatBool($log['decreasing_property_passed']) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= ((int)$log['hardcoded_solution_detected'] === 1) ? 'warning' : 'yes' ?>">
                          <?= formatBool($log['hardcoded_solution_detected']) ?>
                        </span>
                      </td>

                      <td>
                        <span class="<?= scoreClass($score) ?>">
                          <?= number_format($score, 2) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= ((int)$log['completed_successfully'] === 1) ? 'yes' : 'no' ?>">
                          <?= formatBool($log['completed_successfully']) ?>
                        </span>
                      </td>

                      <td>
                        <div class="output-box"><?= escape($log['visible_test_output'] ?? '') ?></div>
                      </td>

                      <td>
                        <div class="output-box"><?= escape($log['hidden_test_1_output'] ?? '') ?></div>
                      </td>

                      <td>
                        <div class="output-box"><?= escape($log['hidden_test_2_output'] ?? '') ?></div>
                      </td>

                      <td>
                        <div class="code-box"><?= escape($log['code_snapshot'] ?? '') ?></div>
                      </td>

                      <td><?= escape($log['created_at'] ?? '-') ?></td>
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
    function toggleSidebar() {
      const sidebar = document.getElementById('sidebar');
      if (sidebar) {
        sidebar.classList.toggle('is-open');
      }
    }

    document.addEventListener('click', function (e) {
      const sidebar = document.getElementById('sidebar');
      const btn = document.querySelector('.mobile-menu-btn');

      if (sidebar && window.innerWidth <= 1024 && sidebar.classList.contains('is-open')) {
        const clickedInsideSidebar = sidebar.contains(e.target);
        const clickedButton = btn && btn.contains(e.target);

        if (!clickedInsideSidebar && !clickedButton) {
          sidebar.classList.remove('is-open');
        }
      }
    });
  </script>
</body>
</html>