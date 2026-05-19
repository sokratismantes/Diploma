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

$selfEfficacyQuestions = [
    'se1' => 'Μπορώ να χωρίσω την υλοποίηση μιας δομής δεδομένων σε μικρότερα βήματα.',
    'se2' => 'Μπορώ να καταλάβω τι είναι μια stack και ποιες βασικές λειτουργίες έχει.',
    'se3' => 'Μπορώ να καταλάβω τι σημαίνει μια stack να διατηρείται μονοτονική.',
    'se4' => 'Μπορώ να καταλάβω τη διαφορά ανάμεσα σε αύξουσα και φθίνουσα μονοτονική στοίβα.',
    'se5' => 'Μπορώ να σχεδιάσω τη βασική λογική λειτουργίας μιας μονοτονικής στοίβας πριν γράψω κώδικα.',
    'se6' => 'Μπορώ να περιγράψω με δικά μου λόγια πώς αλλάζει η στοίβα όταν εισάγεται ένα νέο στοιχείο.',
    'se7' => 'Μπορώ να υλοποιήσω λειτουργία εισαγωγής στοιχείου ώστε η στοίβα να παραμένει μονοτονική.',
    'se8' => 'Μπορώ να παρακολουθήσω βήμα προς βήμα την κατάσταση της στοίβας για μια μικρή ακολουθία εισόδων.',
    'se9' => 'Μπορώ να εντοπίσω και να διορθώσω λάθη στην υλοποίηση μιας μονοτονικής στοίβας.',
    'se10' => 'Πιστεύω ότι μπορώ να υλοποιήσω ξανά μια μονοτονική στοίβα στο μέλλον.',
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

function meanFromKeys(?array $row, array $keys): ?float {
    if (!$row) return null;

    $sum = 0;
    $count = 0;

    foreach ($keys as $key) {
        if (!isset($row[$key]) || $row[$key] === null || $row[$key] === '') {
            return null;
        }
        $sum += (float)$row[$key];
        $count++;
    }

    return $count > 0 ? round($sum / $count, 2) : null;
}

function rowIndicators(?array $row): array {
    return [
        'conceptual_score' => meanFromKeys($row, ['se2', 'se3', 'se4']),
        'planning_reasoning_score' => meanFromKeys($row, ['se1', 'se5', 'se6', 'se8']),
        'implementation_confidence_score' => meanFromKeys($row, ['se7', 'se9', 'se10']),
    ];
}

function buildParticipantAnalysis(string $participantId, array $participantRows, array $participantMeta = []): array {
    $pre = null;
    $post = null;

    foreach ($participantRows as $row) {
        if (($row['questionnaire_type'] ?? '') === 'pre') {
            $pre = $row;
        }

        if (($row['questionnaire_type'] ?? '') === 'post') {
            $post = $row;
        }
    }

    $preIndicators = rowIndicators($pre);
    $postIndicators = rowIndicators($post);

    $preMean = $pre && isset($pre['mean_score']) ? (float)$pre['mean_score'] : null;
    $postMean = $post && isset($post['mean_score']) ? (float)$post['mean_score'] : null;
    $preTotal = $pre && isset($pre['total_score']) ? (float)$pre['total_score'] : null;
    $postTotal = $post && isset($post['total_score']) ? (float)$post['total_score'] : null;

    $selfEfficacyChange = ($preMean !== null && $postMean !== null) ? round($postMean - $preMean, 2) : null;
    $selfEfficacyTotalChange = ($preTotal !== null && $postTotal !== null) ? round($postTotal - $preTotal, 2) : null;

    $changePercent = null;
    if ($preMean !== null && $postMean !== null && $preMean > 0) {
        $changePercent = round((($postMean - $preMean) / $preMean) * 100, 2);
    }

    $normalizedGain = null;
    if ($preMean !== null && $postMean !== null && $preMean < 5) {
        $normalizedGain = round(($postMean - $preMean) / (5 - $preMean), 2);
    }

    $conceptualChange = ($preIndicators['conceptual_score'] !== null && $postIndicators['conceptual_score'] !== null)
        ? round($postIndicators['conceptual_score'] - $preIndicators['conceptual_score'], 2)
        : null;

    $planningReasoningChange = ($preIndicators['planning_reasoning_score'] !== null && $postIndicators['planning_reasoning_score'] !== null)
        ? round($postIndicators['planning_reasoning_score'] - $preIndicators['planning_reasoning_score'], 2)
        : null;

    $implementationConfidenceChange = ($preIndicators['implementation_confidence_score'] !== null && $postIndicators['implementation_confidence_score'] !== null)
        ? round($postIndicators['implementation_confidence_score'] - $preIndicators['implementation_confidence_score'], 2)
        : null;

    $seChanges = [];
    for ($i = 1; $i <= 10; $i++) {
        $key = 'se' . $i;
        $seChanges[$key . '_change'] = ($pre && $post && isset($pre[$key], $post[$key]))
            ? ((int)$post[$key] - (int)$pre[$key])
            : null;
    }

    return array_merge([
        'participant_id' => $participantId,
        'name' => $participantMeta['name'] ?? '-',
        'study_year' => $participantMeta['study_year'] ?? '-',
        'experience' => $participantMeta['experience'] ?? '-',
        'group_type' => $pre['group_type'] ?? $post['group_type'] ?? $participantMeta['assistance_type'] ?? '-',
        'pre' => $pre,
        'post' => $post,
        'rows' => $participantRows,
        'pre_total_score' => $preTotal,
        'post_total_score' => $postTotal,
        'pre_mean_score' => $preMean,
        'post_mean_score' => $postMean,
        'self_efficacy_change' => $selfEfficacyChange,
        'self_efficacy_total_change' => $selfEfficacyTotalChange,
        'self_efficacy_change_percent' => $changePercent,
        'normalized_gain' => $normalizedGain,
        'pre_conceptual_score' => $preIndicators['conceptual_score'],
        'post_conceptual_score' => $postIndicators['conceptual_score'],
        'conceptual_change' => $conceptualChange,
        'pre_planning_reasoning_score' => $preIndicators['planning_reasoning_score'],
        'post_planning_reasoning_score' => $postIndicators['planning_reasoning_score'],
        'planning_reasoning_change' => $planningReasoningChange,
        'pre_implementation_confidence_score' => $preIndicators['implementation_confidence_score'],
        'post_implementation_confidence_score' => $postIndicators['implementation_confidence_score'],
        'implementation_confidence_change' => $implementationConfidenceChange,
        'has_pre_questionnaire' => $pre !== null,
        'has_post_questionnaire' => $post !== null,
        'has_complete_pre_post_pair' => $pre !== null && $post !== null,
    ], $seChanges);
}

function renderRawQuestionnaireTable(?array $row, string $type): string {
    global $selfEfficacyQuestions;

    ob_start();
    ?>
    <div class="questionnaire-card">
      <div class="questionnaire-card-head">
        <div>
          <strong><?= strtoupper($type) ?> Questionnaire</strong>
          <div class="inline-panel-subtitle">Submitted: <?= $row ? escape($row['submitted_at'] ?? '-') : '-' ?></div>
        </div>
        <?php if ($row): ?>
          <div class="score-pill">Mean: <?= escape((string)$row['mean_score']) ?> / 5</div>
        <?php endif; ?>
      </div>

      <?php if (!$row): ?>
        <div class="empty-state">Δεν έχει υποβληθεί <?= strtoupper($type) ?> ερωτηματολόγιο.</div>
      <?php else: ?>
        <div class="detail-grid questionnaire-score-grid" style="margin-bottom: 12px;">
          <div class="detail-box"><div class="detail-label">ID</div><div class="detail-value"><?= escape((string)$row['id']) ?></div></div>
          <div class="detail-box"><div class="detail-label">Participant</div><div class="detail-value"><?= escape($row['participant_id'] ?? '-') ?></div></div>
          <div class="detail-box"><div class="detail-label">Questionnaire Type</div><div class="detail-value"><?= escape($row['questionnaire_type'] ?? '-') ?></div></div>
          <div class="detail-box"><div class="detail-label">Group Type</div><div class="detail-value"><?= escape($row['group_type'] ?? '-') ?></div></div>
          <div class="detail-box"><div class="detail-label">Total Score</div><div class="detail-value"><?= escape((string)$row['total_score']) ?> / 50</div></div>
          <div class="detail-box"><div class="detail-label">Mean Score</div><div class="detail-value"><?= escape((string)$row['mean_score']) ?> / 5</div></div>
          <div class="detail-box"><div class="detail-label">Submitted At</div><div class="detail-value"><?= escape($row['submitted_at'] ?? '-') ?></div></div>
        </div>

        <div class="table-wrap inner-table-wrap">
          <table class="data-table inner-table questionnaire-table">
            <thead>
              <tr><th>Code</th><th>Question</th><th>Answer</th></tr>
            </thead>
            <tbody>
              <?php foreach ($selfEfficacyQuestions as $code => $questionText): ?>
                <tr>
                  <td><span class="table-id"><?= escape(strtoupper($code)) ?></span></td>
                  <td class="question-text-cell"><?= escape($questionText) ?></td>
                  <td><span class="answer-score"><?= escape((string)($row[$code] ?? '-')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function renderAnalysisDetails(array $analysis): string {
    ob_start();
    ?>
    <div class="inline-panel-header">
      <div>
        <strong>SE Score Details</strong>
        <div class="inline-panel-subtitle">Όλοι οι δείκτες για participant: <?= escape($analysis['participant_id']) ?></div>
      </div>
    </div>

    <div class="detail-grid analysis-grid" style="margin-bottom: 12px;">
      <div class="detail-box"><div class="detail-label">Participant ID</div><div class="detail-value"><?= escape($analysis['participant_id']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Group Type</div><div class="detail-value"><?= escape(strtoupper((string)$analysis['group_type'])) ?></div></div>
      <div class="detail-box"><div class="detail-label">Name</div><div class="detail-value"><?= escape((string)$analysis['name']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Complete Pair</div><div class="detail-value"><?= $analysis['has_complete_pre_post_pair'] ? 'Yes' : 'No' ?></div></div>

      <div class="detail-box"><div class="detail-label">Pre Total</div><div class="detail-value"><?= formatNumber($analysis['pre_total_score'], 0) ?> / 50</div></div>
      <div class="detail-box"><div class="detail-label">Post Total</div><div class="detail-value"><?= formatNumber($analysis['post_total_score'], 0) ?> / 50</div></div>
      <div class="detail-box"><div class="detail-label">Total Change</div><div class="detail-value <?= ($analysis['self_efficacy_total_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['self_efficacy_total_change'], 0) ?></div></div>
      <div class="detail-box"><div class="detail-label">Change %</div><div class="detail-value"><?= $analysis['self_efficacy_change_percent'] === null ? '-' : signedNumber($analysis['self_efficacy_change_percent']) . '%' ?></div></div>

      <div class="detail-box"><div class="detail-label">Pre Mean</div><div class="detail-value"><?= formatNumber($analysis['pre_mean_score']) ?> / 5</div></div>
      <div class="detail-box"><div class="detail-label">Post Mean</div><div class="detail-value"><?= formatNumber($analysis['post_mean_score']) ?> / 5</div></div>
      <div class="detail-box"><div class="detail-label">Mean Change</div><div class="detail-value <?= ($analysis['self_efficacy_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['self_efficacy_change']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Normalized Gain</div><div class="detail-value"><?= formatNumber($analysis['normalized_gain']) ?></div></div>

      <div class="detail-box"><div class="detail-label">Pre Conceptual</div><div class="detail-value"><?= formatNumber($analysis['pre_conceptual_score']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Post Conceptual</div><div class="detail-value"><?= formatNumber($analysis['post_conceptual_score']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Conceptual Change</div><div class="detail-value <?= ($analysis['conceptual_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['conceptual_change']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Has Pre/Post</div><div class="detail-value"><?= $analysis['has_pre_questionnaire'] ? 'Pre' : '-' ?><?= $analysis['has_complete_pre_post_pair'] ? ' + ' : '' ?><?= $analysis['has_post_questionnaire'] ? 'Post' : '' ?></div></div>

      <div class="detail-box"><div class="detail-label">Pre Planning</div><div class="detail-value"><?= formatNumber($analysis['pre_planning_reasoning_score']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Post Planning</div><div class="detail-value"><?= formatNumber($analysis['post_planning_reasoning_score']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Planning Change</div><div class="detail-value <?= ($analysis['planning_reasoning_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['planning_reasoning_change']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Experience</div><div class="detail-value"><?= escape((string)$analysis['experience']) ?></div></div>

      <div class="detail-box"><div class="detail-label">Pre Implementation</div><div class="detail-value"><?= formatNumber($analysis['pre_implementation_confidence_score']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Post Implementation</div><div class="detail-value"><?= formatNumber($analysis['post_implementation_confidence_score']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Implementation Change</div><div class="detail-value <?= ($analysis['implementation_confidence_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['implementation_confidence_change']) ?></div></div>
      <div class="detail-box"><div class="detail-label">Study Year</div><div class="detail-value"><?= escape((string)$analysis['study_year']) ?></div></div>
    </div>

    <div class="table-wrap inner-table-wrap" style="margin-bottom: 14px;">
      <table class="data-table inner-table se-change-table">
        <thead>
          <tr><th>Item</th><th>Pre</th><th>Post</th><th>Change</th></tr>
        </thead>
        <tbody>
          <?php for ($i = 1; $i <= 10; $i++): ?>
            <?php $key = 'se' . $i; $changeKey = $key . '_change'; ?>
            <tr>
              <td><span class="table-id"><?= strtoupper($key) ?></span></td>
              <td><?= $analysis['pre'] ? escape((string)($analysis['pre'][$key] ?? '-')) : '-' ?></td>
              <td><?= $analysis['post'] ? escape((string)($analysis['post'][$key] ?? '-')) : '-' ?></td>
              <td><span class="<?= ($analysis[$changeKey] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis[$changeKey], 0) ?></span></td>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div class="questionnaire-cards">
      <?= renderRawQuestionnaireTable($analysis['pre'], 'pre') ?>
      <?= renderRawQuestionnaireTable($analysis['post'], 'post') ?>
    </div>
    <?php
    return ob_get_clean();
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
        $participantStmt = $pdo->query("SELECT participant_id, name, study_year, experience, assistance_type FROM Diploma ORDER BY created_at DESC");
        $participantsMetaRows = $participantStmt->fetchAll();
    } else {
        $participantStmt = $pdo->prepare("SELECT participant_id, name, study_year, experience, assistance_type FROM Diploma WHERE assistance_type = :assistance_type ORDER BY created_at DESC");
        $participantStmt->execute([':assistance_type' => $selectedType]);
        $participantsMetaRows = $participantStmt->fetchAll();
    }

    $participantsMeta = [];
    $participantIds = [];

    foreach ($participantsMetaRows as $row) {
        $pid = (string)$row['participant_id'];
        if (!isset($participantsMeta[$pid])) {
            $participantsMeta[$pid] = $row;
            $participantIds[] = $pid;
        }
    }

    $selfRows = [];

    if (!empty($participantIds)) {
        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $selfStmt = $pdo->prepare("SELECT id, participant_id, questionnaire_type, group_type, se1, se2, se3, se4, se5, se6, se7, se8, se9, se10, total_score, mean_score, submitted_at FROM self_efficacy WHERE participant_id IN ($placeholders) ORDER BY participant_id ASC, FIELD(questionnaire_type, 'pre', 'post') ASC, submitted_at ASC");
        $selfStmt->execute($participantIds);
        $selfRows = $selfStmt->fetchAll();
    }

    $selfEfficacyByParticipant = [];

    foreach ($selfRows as $row) {
        $pid = (string)$row['participant_id'];
        if (!isset($selfEfficacyByParticipant[$pid])) {
            $selfEfficacyByParticipant[$pid] = [];
        }
        $selfEfficacyByParticipant[$pid][] = $row;
    }

    $analyses = [];
    foreach ($participantIds as $pid) {
        $analyses[$pid] = buildParticipantAnalysis($pid, $selfEfficacyByParticipant[$pid] ?? [], $participantsMeta[$pid] ?? []);
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
  <title>Admin Dashboard – SE score</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
        --bg: #0b1324;
        --bg-2: #09101d;
        --sidebar: rgba(9,15,28,.96);
        --surface: rgba(13,20,38,.92);
        --surface-2: rgba(16,24,44,.98);
        --text: #e8eefb;
        --text-soft: #bfd0ef;
        --text-muted: #8393b3;
        --border: rgba(148,163,184,.14);
        --border-strong: rgba(148,163,184,.24);
        --accent-soft: rgba(91,140,255,.14);
        --success-soft: rgba(34,197,94,.14);
        --warning-soft: rgba(245,158,11,.14);
        --danger-soft: rgba(239,68,68,.16);
        --shadow-md: 0 20px 48px rgba(0,0,0,.24);
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
            radial-gradient(circle at 12% 18%, rgba(59,130,246,.14), transparent 28%),
            radial-gradient(circle at 88% 10%, rgba(139,92,246,.10), transparent 24%),
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
        border: 1px solid rgba(239,68,68,.26);
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
        background: rgba(8,14,28,.5);
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
            radial-gradient(circle at top left, rgba(91,140,255,.08), transparent 26%),
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

    .section-subtitle,
    .inline-panel-subtitle {
        font-size: .76rem;
        color: var(--text-muted);
        line-height: 1.5;
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

    .data-table tbody tr {
        transition: background .18s ease;
    }

    .data-table tbody tr:hover {
        background: rgba(255,255,255,.04);
    }

    .data-table tbody tr.active-row {
        background: rgba(91,140,255,.10);
    }

    .clickable-row {
        cursor: pointer;
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

    .badge.complete {
        background: var(--success-soft);
        color: #bbf7d0;
        border-color: rgba(34,197,94,.22);
    }

    .badge.incomplete {
        background: var(--warning-soft);
        color: #fde68a;
        border-color: rgba(245,158,11,.20);
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .detail-box {
        border: 1px solid var(--border);
        background: rgba(255,255,255,.03);
        border-radius: 14px;
        padding: 12px;
        min-width: 0;
    }

    .detail-label {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--text-muted);
        margin-bottom: 6px;
    }

    .detail-value {
        font-size: .8rem;
        color: var(--text);
        line-height: 1.45;
        word-break: break-word;
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

    .score-details-row {
        display: table-row;
    }

    .score-details-row td {
        padding: 0 !important;
        background: transparent;
        white-space: normal;
        border-bottom: 0 !important;
    }

    .details-outer {
        display: grid;
        grid-template-rows: 0fr;
        opacity: 0;
        transition:
            grid-template-rows .32s ease,
            opacity .24s ease;
        margin: 0;
        padding: 0 10px;
    }

    .details-inner {
        overflow: hidden;
        min-height: 0;
        margin: 0;
    }

    .score-details-row.open .details-outer {
        grid-template-rows: 1fr;
        opacity: 1;
    }

    .inline-details-panel {
        padding: 12px 14px 0 14px;
        border-top: 0;
        background: transparent;
        transform: translateY(-4px);
        transition: transform .32s ease;
    }

    .score-details-row.open .inline-details-panel {
        transform: translateY(0);
    }

    .inline-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .questionnaire-cards {
        display: flex;
        flex-direction: column;
        gap: 14px;
        padding: 0 8px 12px;
    }

    .questionnaire-card {
        border: 1px solid var(--border);
        border-radius: 16px;
        background: rgba(255,255,255,.025);
        padding: 12px;
    }

    .questionnaire-card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .score-pill {
        min-height: 30px;
        padding: 0 10px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        background: var(--accent-soft);
        border: 1px solid rgba(91,140,255,.26);
        color: #dbeafe;
        font-size: .72rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .question-text-cell {
        color: var(--text-soft);
        line-height: 1.55;
        min-width: 520px;
    }

    .answer-score {
        width: 32px;
        height: 32px;
        border-radius: 999px;
        display: inline-grid;
        place-items: center;
        background: rgba(91,140,255,.14);
        border: 1px solid rgba(91,140,255,.24);
        color: #dbeafe;
        font-weight: 900;
    }

    .questionnaire-table th:last-child,
    .questionnaire-table td:last-child {
        text-align: left;
        padding-left: 4px;
    }

    .questionnaire-table .answer-score {
        margin-left: -10px;
    }

    .gain-positive {
        color: #86efac;
        font-weight: 900;
    }

    .gain-negative {
        color: #fca5a5;
        font-weight: 900;
    }

    .analysis-grid {
        padding: 0 8px;
    }

    .inner-table {
        min-width: 100%;
    }

    *::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }

    *::-webkit-scrollbar-thumb {
        background: rgba(148,163,184,.18);
        border-radius: 999px;
    }

    *::-webkit-scrollbar-thumb:hover {
        background: rgba(148,163,184,.28);
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
            box-shadow: 0 20px 48px rgba(0,0,0,.34);
        }

        .mobile-menu-btn {
            display: inline-flex;
        }

        .detail-grid,
        .analysis-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
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

        .detail-grid,
        .analysis-grid {
            grid-template-columns: 1fr;
        }

        .page-content {
            padding: 12px;
        }

        .section-card {
            padding: 12px;
        }

        .data-table {
            min-width: 760px;
        }

        .details-outer {
            padding: 0 6px;
        }

        .inline-details-panel {
            padding: 10px 10px 0 10px;
        }

        .analysis-grid,
        .questionnaire-cards {
            padding: 0 4px;
        }
    }
</style>
</head>

<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-title">Admin Dashboard</div>
      <div class="sidebar-subtitle">Self-efficacy scores και pre/post δείκτες.</div>
      <nav class="sidebar-nav">
        <a class="sidebar-link" href="admin_dashboard.php">Participants</a>
        <a class="sidebar-link active" href="admin_se_score.php">SE score</a>
      </nav>
      <div class="sidebar-spacer"></div>
      <a href="?logout=1" class="sidebar-logout">Αποσύνδεση</a>
    </aside>

    <main class="main-content">
      <header class="topbar">
        <div class="topbar-left">
          <button class="mobile-menu-btn" type="button" onclick="toggleSidebar()">☰ Menu</button>
          <div class="topbar-title">SE Score Dashboard</div>
        </div>
        <div class="topbar-actions">
          <a class="topbar-btn <?= $selectedType === 'ai' ? 'active-filter' : '' ?>" href="?type=ai">AI</a>
          <a class="topbar-btn <?= $selectedType === 'video' ? 'active-filter' : '' ?>" href="?type=video">Video</a>
          <a class="topbar-btn <?= $selectedType === 'all' ? 'active-filter' : '' ?>" href="?type=all">Όλοι</a>
        </div>
      </header>

      <div class="page-content">
        <section class="section-card" id="se-score-table">
          <div class="section-head">
            <div>
              <div class="section-title">SE Score Table</div>
              <div class="section-subtitle">Self-efficacy raw scores και υπολογισμένοι pre/post δείκτες ανά participant.</div>
            </div>
            <div><span class="section-subtitle">Σύνολο λίστας: <?= count($analyses) ?></span></div>
          </div>

          <div class="table-wrap">
            <?php if (empty($analyses)): ?>
              <div class="empty-state" style="margin: 12px;">Δεν βρέθηκαν self-efficacy δεδομένα για αυτό το filter.</div>
            <?php else: ?>
              <table class="data-table">
                <thead>
                  <tr>
                    <th>Participant ID</th>
                    <th>Group</th>
                    <th>Pre Mean</th>
                    <th>Post Mean</th>
                    <th>Change</th>
                    <th>Normalized Gain</th>
                    <th>Conceptual Δ</th>
                    <th>Planning Δ</th>
                    <th>Implementation Δ</th>
                    <th>Complete</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($analyses as $pid => $analysis): ?>
                    <?php $detailId = 'se-score-details-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $pid); ?>
                    <tr class="clickable-row score-row" data-detail-id="<?= escape($detailId) ?>">
                      <td><span class="table-id"><?= escape($pid) ?></span></td>
                      <td><span class="badge <?= ($analysis['group_type'] ?? '') === 'video' ? 'video' : 'ai' ?>"><?= strtoupper(escape((string)$analysis['group_type'])) ?></span></td>
                      <td><?= formatNumber($analysis['pre_mean_score']) ?></td>
                      <td><?= formatNumber($analysis['post_mean_score']) ?></td>
                      <td><span class="<?= ($analysis['self_efficacy_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['self_efficacy_change']) ?></span></td>
                      <td><?= formatNumber($analysis['normalized_gain']) ?></td>
                      <td><span class="<?= ($analysis['conceptual_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['conceptual_change']) ?></span></td>
                      <td><span class="<?= ($analysis['planning_reasoning_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['planning_reasoning_change']) ?></span></td>
                      <td><span class="<?= ($analysis['implementation_confidence_change'] ?? 0) >= 0 ? 'gain-positive' : 'gain-negative' ?>"><?= signedNumber($analysis['implementation_confidence_change']) ?></span></td>
                      <td><span class="badge <?= $analysis['has_complete_pre_post_pair'] ? 'complete' : 'incomplete' ?>"><?= $analysis['has_complete_pre_post_pair'] ? 'PRE + POST' : 'INCOMPLETE' ?></span></td>
                    </tr>
                    <tr class="score-details-row" id="<?= escape($detailId) ?>">
                      <td colspan="10">
                        <div class="details-outer">
                          <div class="inline-details-panel details-inner">
                            <?= renderAnalysisDetails($analysis) ?>
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
    function closeAllScorePanels(){document.querySelectorAll('.score-details-row').forEach(row=>row.classList.remove('open'));document.querySelectorAll('.score-row').forEach(row=>row.classList.remove('active-row'))}
    document.addEventListener('click',function(e){const sidebar=document.getElementById('sidebar');const btn=document.querySelector('.mobile-menu-btn');if(sidebar&&window.innerWidth<=1024&&sidebar.classList.contains('is-open')){const clickedInsideSidebar=sidebar.contains(e.target);const clickedButton=btn&&btn.contains(e.target);if(!clickedInsideSidebar&&!clickedButton){sidebar.classList.remove('is-open')}}const scoreRow=e.target.closest('.score-row');if(scoreRow){const detailId=scoreRow.dataset.detailId;const detailsRow=document.getElementById(detailId);if(!detailsRow)return;const isOpen=detailsRow.classList.contains('open');closeAllScorePanels();if(!isOpen){detailsRow.classList.add('open');scoreRow.classList.add('active-row')}}});
  </script>
</body>
</html>
