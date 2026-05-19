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

function formatNumber($value, int $decimals = 2): string {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value, $decimals, '.', '');
}

function signedNumber($value, int $decimals = 2): string {
    if ($value === null || $value === '') return '-';
    $num = (float)$value;
    return ($num > 0 ? '+' : '') . number_format($num, $decimals, '.', '');
}

function percentNumber($value, int $decimals = 2): string {
    if ($value === null || $value === '') return '-';
    return number_format((float)$value, $decimals, '.', '') . '%';
}

function safeDivide($numerator, $denominator): ?float {
    $denominator = (float)$denominator;
    if ($denominator <= 0) return null;
    return round(((float)$numerator / $denominator) * 100, 2);
}

function tableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $stmt->execute([
        ':table_name' => $tableName
    ]);

    $row = $stmt->fetch();

    return (int)($row['total'] ?? 0) > 0;
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
 * DEFAULT DATA
 */
$participants = [];
$objectiveRows = [];
$selfRows = [];

$aiObjective = [
    'total_logs' => 0,
    'completed_count' => 0,
    'avg_objective_score' => 0,
    'visible_passed_count' => 0,
    'hidden_1_passed_count' => 0,
    'hidden_2_passed_count' => 0,
    'decreasing_passed_count' => 0,
    'hardcoded_count' => 0,
];

$videoObjective = $aiObjective;

$aiSe = [
    'participants_with_pre' => 0,
    'participants_with_post' => 0,
    'complete_pairs' => 0,
    'avg_pre_mean' => 0,
    'avg_post_mean' => 0,
    'avg_gain' => 0,
];

$videoSe = $aiSe;

$objectiveByGroup = [
    'ai_assistance' => $aiObjective,
    'video_tutorial' => $videoObjective,
];

$seByGroup = [
    'ai' => $aiSe,
    'video' => $videoSe,
];

$combinedResults = [];
$dbError = '';

/**
 * DB LOAD
 */
try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    $hasDiplomaTable = tableExists($pdo, 'Diploma');
    $hasObjectiveTable = tableExists($pdo, 'objective_success_logs');
    $hasSelfEfficacyTable = tableExists($pdo, 'self_efficacy');

    if ($hasDiplomaTable) {
        if ($selectedType === 'all') {
            $stmt = $pdo->query("
                SELECT id, participant_id, name, study_year, experience, assistance_type, created_at
                FROM Diploma
                ORDER BY created_at DESC
            ");
            $participants = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("
                SELECT id, participant_id, name, study_year, experience, assistance_type, created_at
                FROM Diploma
                WHERE assistance_type = :assistance_type
                ORDER BY created_at DESC
            ");
            $stmt->execute([
                ':assistance_type' => $selectedType
            ]);
            $participants = $stmt->fetchAll();
        }
    }

    $participantMeta = [];
    foreach ($participants as $participant) {
        $pid = (string)$participant['participant_id'];
        if (!isset($participantMeta[$pid])) {
            $participantMeta[$pid] = $participant;
        }
    }

    if ($hasObjectiveTable) {
        if ($selectedType === 'all') {
            $stmt = $pdo->query("
                SELECT *
                FROM objective_success_logs
                ORDER BY created_at DESC
            ");
            $objectiveRows = $stmt->fetchAll();
        } else {
            $condition = $selectedType === 'ai' ? 'ai_assistance' : 'video_tutorial';

            $stmt = $pdo->prepare("
                SELECT *
                FROM objective_success_logs
                WHERE `condition` = :condition
                ORDER BY created_at DESC
            ");
            $stmt->execute([
                ':condition' => $condition
            ]);
            $objectiveRows = $stmt->fetchAll();
        }

        $stmt = $pdo->query("
            SELECT
                `condition`,
                COUNT(*) AS total_logs,
                SUM(CASE WHEN completed_successfully = 1 THEN 1 ELSE 0 END) AS completed_count,
                AVG(final_objective_success_score) AS avg_objective_score,
                SUM(CASE WHEN visible_test_passed = 1 THEN 1 ELSE 0 END) AS visible_passed_count,
                SUM(CASE WHEN hidden_test_1_passed = 1 THEN 1 ELSE 0 END) AS hidden_1_passed_count,
                SUM(CASE WHEN hidden_test_2_passed = 1 THEN 1 ELSE 0 END) AS hidden_2_passed_count,
                SUM(CASE WHEN decreasing_property_passed = 1 THEN 1 ELSE 0 END) AS decreasing_passed_count,
                SUM(CASE WHEN hardcoded_solution_detected = 1 THEN 1 ELSE 0 END) AS hardcoded_count
            FROM objective_success_logs
            GROUP BY `condition`
        ");

        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $condition = (string)($row['condition'] ?? '');

            $objectiveByGroup[$condition] = [
                'total_logs' => (int)($row['total_logs'] ?? 0),
                'completed_count' => (int)($row['completed_count'] ?? 0),
                'avg_objective_score' => round((float)($row['avg_objective_score'] ?? 0), 2),
                'visible_passed_count' => (int)($row['visible_passed_count'] ?? 0),
                'hidden_1_passed_count' => (int)($row['hidden_1_passed_count'] ?? 0),
                'hidden_2_passed_count' => (int)($row['hidden_2_passed_count'] ?? 0),
                'decreasing_passed_count' => (int)($row['decreasing_passed_count'] ?? 0),
                'hardcoded_count' => (int)($row['hardcoded_count'] ?? 0),
            ];
        }

        $aiObjective = $objectiveByGroup['ai_assistance'] ?? $aiObjective;
        $videoObjective = $objectiveByGroup['video_tutorial'] ?? $videoObjective;
    }

    if ($hasSelfEfficacyTable) {
        if ($selectedType === 'all') {
            $stmt = $pdo->query("
                SELECT *
                FROM self_efficacy
                ORDER BY participant_id ASC, FIELD(questionnaire_type, 'pre', 'post') ASC, submitted_at ASC
            ");
            $selfRows = $stmt->fetchAll();
        } else {
            $stmt = $pdo->prepare("
                SELECT *
                FROM self_efficacy
                WHERE group_type = :group_type
                ORDER BY participant_id ASC, FIELD(questionnaire_type, 'pre', 'post') ASC, submitted_at ASC
            ");
            $stmt->execute([
                ':group_type' => $selectedType
            ]);
            $selfRows = $stmt->fetchAll();
        }

        $selfByParticipant = [];
        foreach ($selfRows as $row) {
            $pid = (string)$row['participant_id'];
            if (!isset($selfByParticipant[$pid])) {
                $selfByParticipant[$pid] = [];
            }

            $selfByParticipant[$pid][] = $row;
        }

        $seTemp = [
            'ai' => [
                'pre_count' => 0,
                'post_count' => 0,
                'complete_pairs' => 0,
                'pre_sum' => 0,
                'post_sum' => 0,
                'gain_sum' => 0,
            ],
            'video' => [
                'pre_count' => 0,
                'post_count' => 0,
                'complete_pairs' => 0,
                'pre_sum' => 0,
                'post_sum' => 0,
                'gain_sum' => 0,
            ],
        ];

        foreach ($selfByParticipant as $pid => $rows) {
            $pre = null;
            $post = null;
            $groupType = null;

            foreach ($rows as $row) {
                $type = (string)($row['questionnaire_type'] ?? '');

                if ($type === 'pre') {
                    $pre = $row;
                }

                if ($type === 'post') {
                    $post = $row;
                }

                if (!$groupType && !empty($row['group_type'])) {
                    $groupType = (string)$row['group_type'];
                }
            }

            if (!$groupType && isset($participantMeta[$pid]['assistance_type'])) {
                $groupType = (string)$participantMeta[$pid]['assistance_type'];
            }

            if (!in_array($groupType, ['ai', 'video'], true)) {
                continue;
            }

            if ($pre) {
                $seTemp[$groupType]['pre_count']++;
                $seTemp[$groupType]['pre_sum'] += (float)($pre['mean_score'] ?? 0);
            }

            if ($post) {
                $seTemp[$groupType]['post_count']++;
                $seTemp[$groupType]['post_sum'] += (float)($post['mean_score'] ?? 0);
            }

            if ($pre && $post) {
                $seTemp[$groupType]['complete_pairs']++;
                $seTemp[$groupType]['gain_sum'] += ((float)($post['mean_score'] ?? 0) - (float)($pre['mean_score'] ?? 0));
            }
        }

        foreach (['ai', 'video'] as $group) {
            $seByGroup[$group] = [
                'participants_with_pre' => $seTemp[$group]['pre_count'],
                'participants_with_post' => $seTemp[$group]['post_count'],
                'complete_pairs' => $seTemp[$group]['complete_pairs'],
                'avg_pre_mean' => $seTemp[$group]['pre_count'] > 0 ? round($seTemp[$group]['pre_sum'] / $seTemp[$group]['pre_count'], 2) : 0,
                'avg_post_mean' => $seTemp[$group]['post_count'] > 0 ? round($seTemp[$group]['post_sum'] / $seTemp[$group]['post_count'], 2) : 0,
                'avg_gain' => $seTemp[$group]['complete_pairs'] > 0 ? round($seTemp[$group]['gain_sum'] / $seTemp[$group]['complete_pairs'], 2) : 0,
            ];
        }

        $aiSe = $seByGroup['ai'];
        $videoSe = $seByGroup['video'];
    }

    /**
     * COMBINED PARTICIPANT RESULTS
     */
    $latestObjectiveByUser = [];

    foreach ($objectiveRows as $row) {
        $uid = (string)($row['user_id'] ?? '');

        if ($uid === '') {
            continue;
        }

        if (!isset($latestObjectiveByUser[$uid])) {
            $latestObjectiveByUser[$uid] = $row;
        }
    }

    $selfByParticipantCombined = [];

    foreach ($selfRows as $row) {
        $pid = (string)($row['participant_id'] ?? '');
        if ($pid === '') continue;

        if (!isset($selfByParticipantCombined[$pid])) {
            $selfByParticipantCombined[$pid] = [
                'pre' => null,
                'post' => null,
            ];
        }

        if (($row['questionnaire_type'] ?? '') === 'pre') {
            $selfByParticipantCombined[$pid]['pre'] = $row;
        }

        if (($row['questionnaire_type'] ?? '') === 'post') {
            $selfByParticipantCombined[$pid]['post'] = $row;
        }
    }

    foreach ($participantMeta as $pid => $meta) {
        $condition = ($meta['assistance_type'] ?? '') === 'ai' ? 'ai_assistance' : 'video_tutorial';
        $objective = $latestObjectiveByUser[$pid] ?? null;
        $selfPair = $selfByParticipantCombined[$pid] ?? ['pre' => null, 'post' => null];

        $preMean = $selfPair['pre'] ? (float)($selfPair['pre']['mean_score'] ?? 0) : null;
        $postMean = $selfPair['post'] ? (float)($selfPair['post']['mean_score'] ?? 0) : null;

        $combinedResults[] = [
            'participant_id' => $pid,
            'name' => $meta['name'] ?? '-',
            'assistance_type' => $meta['assistance_type'] ?? '-',
            'objective_score' => $objective ? (float)($objective['final_objective_success_score'] ?? 0) : null,
            'completed_successfully' => $objective ? (int)($objective['completed_successfully'] ?? 0) : null,
            'pre_mean' => $preMean,
            'post_mean' => $postMean,
            'se_gain' => ($preMean !== null && $postMean !== null) ? round($postMean - $preMean, 2) : null,
            'has_objective' => $objective !== null,
            'has_complete_se_pair' => $selfPair['pre'] !== null && $selfPair['post'] !== null,
        ];
    }

} catch (PDOException $e) {
    $dbError = 'Σφάλμα σύνδεσης με τη βάση: ' . $e->getMessage();
}

$totalParticipants = count($participants);
$totalObjectiveLogs = (int)$aiObjective['total_logs'] + (int)$videoObjective['total_logs'];
$totalCompleted = (int)$aiObjective['completed_count'] + (int)$videoObjective['completed_count'];
$totalCompletePairs = (int)$aiSe['complete_pairs'] + (int)$videoSe['complete_pairs'];

$aiCompletionRate = safeDivide($aiObjective['completed_count'], $aiObjective['total_logs']);
$videoCompletionRate = safeDivide($videoObjective['completed_count'], $videoObjective['total_logs']);

$aiVisibleRate = safeDivide($aiObjective['visible_passed_count'], $aiObjective['total_logs']);
$videoVisibleRate = safeDivide($videoObjective['visible_passed_count'], $videoObjective['total_logs']);

$aiHidden1Rate = safeDivide($aiObjective['hidden_1_passed_count'], $aiObjective['total_logs']);
$videoHidden1Rate = safeDivide($videoObjective['hidden_1_passed_count'], $videoObjective['total_logs']);

$aiHidden2Rate = safeDivide($aiObjective['hidden_2_passed_count'], $aiObjective['total_logs']);
$videoHidden2Rate = safeDivide($videoObjective['hidden_2_passed_count'], $videoObjective['total_logs']);

$aiDecreasingRate = safeDivide($aiObjective['decreasing_passed_count'], $aiObjective['total_logs']);
$videoDecreasingRate = safeDivide($videoObjective['decreasing_passed_count'], $videoObjective['total_logs']);

$aiHardcodedRate = safeDivide($aiObjective['hardcoded_count'], $aiObjective['total_logs']);
$videoHardcodedRate = safeDivide($videoObjective['hardcoded_count'], $videoObjective['total_logs']);
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />

  <title>Admin Dashboard – Results</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://www.gstatic.com/charts/loader.js"></script>

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
      --accent: #5b8cff;
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
      padding: 14px;
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

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }

    .summary-box,
    .metric-box {
      border: 1px solid var(--border);
      background: rgba(255,255,255,.03);
      border-radius: 16px;
      padding: 14px;
      min-width: 0;
    }

    .summary-label,
    .metric-label {
      font-size: .68rem;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--text-muted);
      margin-bottom: 6px;
    }

    .summary-value,
    .metric-value {
      font-size: 1.2rem;
      color: var(--text);
      font-weight: 900;
      line-height: 1.25;
      word-break: break-word;
    }

    .summary-help,
    .metric-help {
      color: var(--text-muted);
      font-size: .74rem;
      line-height: 1.5;
      margin-top: 6px;
    }

    .comparison-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .comparison-card {
      border: 1px solid var(--border);
      border-radius: 18px;
      background:
        radial-gradient(circle at top left, rgba(91,140,255,0.06), transparent 30%),
        rgba(255,255,255,.025);
      padding: 14px;
    }

    .comparison-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }

    .comparison-title strong {
      font-size: .9rem;
    }

    .metric-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
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

    .badge.warning {
      background: var(--warning-soft);
      color: #fde68a;
      border-color: rgba(245,158,11,.20);
    }

    .badge.danger {
      background: var(--danger-soft);
      color: #fecaca;
      border-color: rgba(239,68,68,.22);
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
      min-width: 980px;
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

    .data-table tbody tr:hover {
      background: rgba(255,255,255,.04);
    }

    .table-id {
      font-weight: 800;
      color: var(--text);
    }

    .gain-positive {
      color: #86efac;
      font-weight: 900;
    }

    .gain-negative {
      color: #fca5a5;
      font-weight: 900;
    }

    .empty-state,
    .error-state {
      border: 1px dashed rgba(148,163,184,.22);
      border-radius: 14px;
      padding: 14px;
      color: var(--text-soft);
      font-size: .8rem;
      line-height: 1.6;
      background: rgba(255,255,255,.02);
    }

    .error-state {
      border-color: rgba(239,68,68,.28);
      background: rgba(239,68,68,.08);
      color: #fecaca;
    }

    .charts-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .chart-card {
      border: 1px solid var(--border);
      border-radius: 18px;
      background:
        radial-gradient(circle at top left, rgba(91,140,255,0.08), transparent 28%),
        rgba(255,255,255,.03);
      padding: 14px;
      min-height: 360px;
      overflow: hidden;
    }

    .chart-title {
      font-size: .9rem;
      font-weight: 850;
      color: var(--text);
      margin-bottom: 4px;
    }

    .chart-subtitle {
      font-size: .74rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-bottom: 10px;
    }

    .chart-box {
      width: 100%;
      height: 300px;
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

    @media (max-width: 1100px) {
      .summary-grid,
      .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .comparison-grid,
      .charts-grid {
        grid-template-columns: 1fr;
      }
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

      .summary-grid,
      .metric-grid {
        grid-template-columns: 1fr;
      }

      .page-content {
        padding: 12px;
      }

      .section-card {
        padding: 12px;
      }

      .data-table {
        min-width: 820px;
      }
    }
  </style>
</head>

<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-title">Admin Dashboard</div>
      <div class="sidebar-subtitle">Συγκεντρωτικά αποτελέσματα objective success και self-efficacy.</div>

      <nav class="sidebar-nav">
        <a class="sidebar-link" href="admin_dashboard.php">Participants</a>
        <a class="sidebar-link" href="admin_se_score.php">SE score</a>
        <a class="sidebar-link" href="admin_objective_success.php">Objective Success</a>
        <a class="sidebar-link active" href="admin_results.php">Results</a>
      </nav>

      <div class="sidebar-spacer"></div>

      <a href="?logout=1" class="sidebar-logout">Αποσύνδεση</a>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div class="topbar-left">
          <button class="mobile-menu-btn" type="button" onclick="toggleSidebar()">☰ Menu</button>
          <div class="topbar-title">Results Dashboard</div>
        </div>

        <div class="topbar-actions">
          <a class="topbar-btn <?= $selectedType === 'ai' ? 'active-filter' : '' ?>" href="?type=ai">AI</a>
          <a class="topbar-btn <?= $selectedType === 'video' ? 'active-filter' : '' ?>" href="?type=video">Video</a>
          <a class="topbar-btn <?= $selectedType === 'all' ? 'active-filter' : '' ?>" href="?type=all">Όλοι</a>
        </div>
      </header>

      <div class="page-content">
        <?php if ($dbError !== ''): ?>
          <div class="error-state">
            <?= escape($dbError) ?>
          </div>
        <?php endif; ?>

        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">Overall Summary</div>
              <div class="section-subtitle">
                Συγκεντρωτική εικόνα συμμετεχόντων, objective logs και complete self-efficacy pairs.
              </div>
            </div>
          </div>

          <div class="summary-grid">
            <div class="summary-box">
              <div class="summary-label">Participants</div>
              <div class="summary-value"><?= (int)$totalParticipants ?></div>
              <div class="summary-help">Συμμετέχοντες με βάση το ενεργό filter.</div>
            </div>

            <div class="summary-box">
              <div class="summary-label">Objective Logs</div>
              <div class="summary-value"><?= (int)$totalObjectiveLogs ?></div>
              <div class="summary-help">Υποβολές στον πίνακα objective_success_logs.</div>
            </div>

            <div class="summary-box">
              <div class="summary-label">Completed Successfully</div>
              <div class="summary-value"><?= (int)$totalCompleted ?></div>
              <div class="summary-help">Επιτυχείς objective ολοκληρώσεις.</div>
            </div>

            <div class="summary-box">
              <div class="summary-label">Complete SE Pairs</div>
              <div class="summary-value"><?= (int)$totalCompletePairs ?></div>
              <div class="summary-help">Συμμετέχοντες με pre και post self-efficacy.</div>
            </div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">3D Pie Charts</div>
              <div class="section-subtitle">
                Οπτική σύγκριση AI Assistance και Video Tutorial με βάση objective success και self-efficacy.
              </div>
            </div>
          </div>

          <div class="charts-grid">
            <div class="chart-card">
              <div class="chart-title">Objective Logs ανά ομάδα</div>
              <div class="chart-subtitle">Πόσα objective logs έχουν καταγραφεί για κάθε ομάδα.</div>
              <div id="objectiveLogsPie" class="chart-box"></div>
            </div>

            <div class="chart-card">
              <div class="chart-title">Completed Successfully ανά ομάδα</div>
              <div class="chart-subtitle">Πόσες επιτυχείς ολοκληρώσεις υπάρχουν ανά ομάδα.</div>
              <div id="completedPie" class="chart-box"></div>
            </div>

            <div class="chart-card">
              <div class="chart-title">Self-Efficacy Complete Pairs</div>
              <div class="chart-subtitle">Πόσα πλήρη pre/post ζεύγη υπάρχουν ανά ομάδα.</div>
              <div id="selfEfficacyPairsPie" class="chart-box"></div>
            </div>

            <div class="chart-card">
              <div class="chart-title">Average Objective Score</div>
              <div class="chart-subtitle">Σύγκριση μέσου objective score μεταξύ AI και Video.</div>
              <div id="avgObjectiveScorePie" class="chart-box"></div>
            </div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">Objective Success Comparison</div>
              <div class="section-subtitle">
                Σύγκριση αντικειμενικής επιτυχίας ανάμεσα στις δύο ομάδες.
              </div>
            </div>
          </div>

          <div class="comparison-grid">
            <div class="comparison-card">
              <div class="comparison-title">
                <strong>AI Assistance</strong>
                <span class="badge ai">AI</span>
              </div>

              <div class="metric-grid">
                <div class="metric-box">
                  <div class="metric-label">Total Logs</div>
                  <div class="metric-value"><?= (int)$aiObjective['total_logs'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Avg Score</div>
                  <div class="metric-value"><?= formatNumber($aiObjective['avg_objective_score']) ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Completed Rate</div>
                  <div class="metric-value"><?= percentNumber($aiCompletionRate) ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Hardcoded Rate</div>
                  <div class="metric-value"><?= percentNumber($aiHardcodedRate) ?></div>
                </div>
              </div>
            </div>

            <div class="comparison-card">
              <div class="comparison-title">
                <strong>Video Tutorial</strong>
                <span class="badge video">VIDEO</span>
              </div>

              <div class="metric-grid">
                <div class="metric-box">
                  <div class="metric-label">Total Logs</div>
                  <div class="metric-value"><?= (int)$videoObjective['total_logs'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Avg Score</div>
                  <div class="metric-value"><?= formatNumber($videoObjective['avg_objective_score']) ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Completed Rate</div>
                  <div class="metric-value"><?= percentNumber($videoCompletionRate) ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Hardcoded Rate</div>
                  <div class="metric-value"><?= percentNumber($videoHardcodedRate) ?></div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">Test Pass Rates</div>
              <div class="section-subtitle">
                Ποσοστά επιτυχίας στα βασικά objective validation checks.
              </div>
            </div>
          </div>

          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Metric</th>
                  <th>AI Assistance</th>
                  <th>Video Tutorial</th>
                  <th>Difference</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td><span class="table-id">Visible Test Passed</span></td>
                  <td><?= percentNumber($aiVisibleRate) ?></td>
                  <td><?= percentNumber($videoVisibleRate) ?></td>
                  <td><?= ($aiVisibleRate !== null && $videoVisibleRate !== null) ? signedNumber($aiVisibleRate - $videoVisibleRate) . '%' : '-' ?></td>
                </tr>

                <tr>
                  <td><span class="table-id">Hidden Test 1 Passed</span></td>
                  <td><?= percentNumber($aiHidden1Rate) ?></td>
                  <td><?= percentNumber($videoHidden1Rate) ?></td>
                  <td><?= ($aiHidden1Rate !== null && $videoHidden1Rate !== null) ? signedNumber($aiHidden1Rate - $videoHidden1Rate) . '%' : '-' ?></td>
                </tr>

                <tr>
                  <td><span class="table-id">Hidden Test 2 Passed</span></td>
                  <td><?= percentNumber($aiHidden2Rate) ?></td>
                  <td><?= percentNumber($videoHidden2Rate) ?></td>
                  <td><?= ($aiHidden2Rate !== null && $videoHidden2Rate !== null) ? signedNumber($aiHidden2Rate - $videoHidden2Rate) . '%' : '-' ?></td>
                </tr>

                <tr>
                  <td><span class="table-id">Decreasing Property Passed</span></td>
                  <td><?= percentNumber($aiDecreasingRate) ?></td>
                  <td><?= percentNumber($videoDecreasingRate) ?></td>
                  <td><?= ($aiDecreasingRate !== null && $videoDecreasingRate !== null) ? signedNumber($aiDecreasingRate - $videoDecreasingRate) . '%' : '-' ?></td>
                </tr>

                <tr>
                  <td><span class="table-id">Hardcoded Solution Detected</span></td>
                  <td><?= percentNumber($aiHardcodedRate) ?></td>
                  <td><?= percentNumber($videoHardcodedRate) ?></td>
                  <td><?= ($aiHardcodedRate !== null && $videoHardcodedRate !== null) ? signedNumber($aiHardcodedRate - $videoHardcodedRate) . '%' : '-' ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">Self-Efficacy Comparison</div>
              <div class="section-subtitle">
                Σύγκριση pre/post self-efficacy ανά ομάδα.
              </div>
            </div>
          </div>

          <div class="comparison-grid">
            <div class="comparison-card">
              <div class="comparison-title">
                <strong>AI Assistance</strong>
                <span class="badge ai">AI</span>
              </div>

              <div class="metric-grid">
                <div class="metric-box">
                  <div class="metric-label">Pre Submitted</div>
                  <div class="metric-value"><?= (int)$aiSe['participants_with_pre'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Post Submitted</div>
                  <div class="metric-value"><?= (int)$aiSe['participants_with_post'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Complete Pairs</div>
                  <div class="metric-value"><?= (int)$aiSe['complete_pairs'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Avg Gain</div>
                  <div class="metric-value <?= ($aiSe['avg_gain'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>">
                    <?= signedNumber($aiSe['avg_gain']) ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="comparison-card">
              <div class="comparison-title">
                <strong>Video Tutorial</strong>
                <span class="badge video">VIDEO</span>
              </div>

              <div class="metric-grid">
                <div class="metric-box">
                  <div class="metric-label">Pre Submitted</div>
                  <div class="metric-value"><?= (int)$videoSe['participants_with_pre'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Post Submitted</div>
                  <div class="metric-value"><?= (int)$videoSe['participants_with_post'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Complete Pairs</div>
                  <div class="metric-value"><?= (int)$videoSe['complete_pairs'] ?></div>
                </div>

                <div class="metric-box">
                  <div class="metric-label">Avg Gain</div>
                  <div class="metric-value <?= ($videoSe['avg_gain'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>">
                    <?= signedNumber($videoSe['avg_gain']) ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="section-card">
          <div class="section-head">
            <div>
              <div class="section-title">Combined Participant Results</div>
              <div class="section-subtitle">
                Συνδυαστική προβολή objective score και self-efficacy gain ανά participant.
              </div>
            </div>
          </div>

          <div class="table-wrap">
            <?php if (empty($combinedResults)): ?>
              <div class="empty-state" style="margin: 12px;">
                Δεν βρέθηκαν συγκεντρωτικά αποτελέσματα.
              </div>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Participant ID</th>
                    <th>Group</th>
                    <th>Name</th>
                    <th>Objective Score</th>
                    <th>Completed</th>
                    <th>Pre Mean</th>
                    <th>Post Mean</th>
                    <th>SE Gain</th>
                    <th>Objective Log</th>
                    <th>SE Pair</th>
                  </tr>
                </thead>

                <tbody>
                  <?php foreach ($combinedResults as $row): ?>
                    <tr>
                      <td><span class="table-id"><?= escape($row['participant_id']) ?></span></td>

                      <td>
                        <span class="badge <?= $row['assistance_type'] === 'video' ? 'video' : 'ai' ?>">
                          <?= strtoupper(escape((string)$row['assistance_type'])) ?>
                        </span>
                      </td>

                      <td><?= escape((string)$row['name']) ?></td>
                      <td><?= formatNumber($row['objective_score']) ?></td>

                      <td>
                        <?php if ($row['completed_successfully'] === null): ?>
                          -
                        <?php elseif ((int)$row['completed_successfully'] === 1): ?>
                          <span class="badge video">YES</span>
                        <?php else: ?>
                          <span class="badge danger">NO</span>
                        <?php endif; ?>
                      </td>

                      <td><?= formatNumber($row['pre_mean']) ?></td>
                      <td><?= formatNumber($row['post_mean']) ?></td>

                      <td>
                        <span class="<?= ($row['se_gain'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>">
                          <?= signedNumber($row['se_gain']) ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= $row['has_objective'] ? 'video' : 'warning' ?>">
                          <?= $row['has_objective'] ? 'YES' : 'NO' ?>
                        </span>
                      </td>

                      <td>
                        <span class="badge <?= $row['has_complete_se_pair'] ? 'video' : 'warning' ?>">
                          <?= $row['has_complete_se_pair'] ? 'PRE + POST' : 'INCOMPLETE' ?>
                        </span>
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

    google.charts.load('current', { packages: ['corechart'] });
    google.charts.setOnLoadCallback(drawResultCharts);

    function drawResultCharts() {
      const textColor = '#e8eefb';

      const commonOptions = {
        is3D: true,
        backgroundColor: 'transparent',
        legend: {
          textStyle: {
            color: textColor,
            fontSize: 12
          }
        },
        chartArea: {
          left: 10,
          top: 20,
          width: '90%',
          height: '78%'
        },
        titleTextStyle: {
          color: textColor,
          fontSize: 14,
          bold: true
        },
        pieSliceTextStyle: {
          color: '#ffffff',
          fontSize: 12
        },
        tooltip: {
          textStyle: {
            color: '#111827'
          }
        },
        slices: {
          0: { color: '#5b8cff' },
          1: { color: '#22c55e' }
        }
      };

      const objectiveLogsData = google.visualization.arrayToDataTable([
        ['Group', 'Logs'],
        ['AI Assistance', <?= (int)($aiObjective['total_logs'] ?? 0) ?>],
        ['Video Tutorial', <?= (int)($videoObjective['total_logs'] ?? 0) ?>]
      ]);

      const completedData = google.visualization.arrayToDataTable([
        ['Group', 'Completed'],
        ['AI Assistance', <?= (int)($aiObjective['completed_count'] ?? 0) ?>],
        ['Video Tutorial', <?= (int)($videoObjective['completed_count'] ?? 0) ?>]
      ]);

      const selfEfficacyPairsData = google.visualization.arrayToDataTable([
        ['Group', 'Complete Pre/Post Pairs'],
        ['AI Assistance', <?= (int)($aiSe['complete_pairs'] ?? 0) ?>],
        ['Video Tutorial', <?= (int)($videoSe['complete_pairs'] ?? 0) ?>]
      ]);

      const avgObjectiveScoreData = google.visualization.arrayToDataTable([
        ['Group', 'Average Objective Score'],
        ['AI Assistance', <?= (float)($aiObjective['avg_objective_score'] ?? 0) ?>],
        ['Video Tutorial', <?= (float)($videoObjective['avg_objective_score'] ?? 0) ?>]
      ]);

      new google.visualization.PieChart(document.getElementById('objectiveLogsPie'))
        .draw(objectiveLogsData, {
          ...commonOptions,
          title: 'Objective Logs'
        });

      new google.visualization.PieChart(document.getElementById('completedPie'))
        .draw(completedData, {
          ...commonOptions,
          title: 'Completed Successfully'
        });

      new google.visualization.PieChart(document.getElementById('selfEfficacyPairsPie'))
        .draw(selfEfficacyPairsData, {
          ...commonOptions,
          title: 'Complete Pre/Post Pairs'
        });

      new google.visualization.PieChart(document.getElementById('avgObjectiveScorePie'))
        .draw(avgObjectiveScoreData, {
          ...commonOptions,
          title: 'Average Objective Score'
        });
    }

    window.addEventListener('resize', function () {
      if (window.google && google.visualization) {
        drawResultCharts();
      }
    });
  </script>
</body>
</html>