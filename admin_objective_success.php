<?php
session_start();

require_once __DIR__ . '/ai_submission_evaluator.php';

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

function objectiveFailureReason(array $log): string {
    $failed = [];

    if ((int)($log['visible_test_passed'] ?? 0) !== 1) $failed[] = 'Visible test failure';
    if ((int)($log['hidden_test_1_passed'] ?? 0) !== 1) $failed[] = 'Hidden test 1 failure';
    if ((int)($log['hidden_test_2_passed'] ?? 0) !== 1) $failed[] = 'Hidden test 2 failure';
    if ((int)($log['decreasing_property_passed'] ?? 0) !== 1) $failed[] = 'Decreasing property failure';
    if ((int)($log['hardcoded_solution_detected'] ?? 0) === 1) $failed[] = 'Hardcoded solution detected';

    if (empty($failed)) return 'All tests passed';
    if (count($failed) === 1) return $failed[0];
    return 'Multiple failures';
}

function normalizeReviewHtml(?string $html): string {
    $html = (string)$html;

    if ($html === '') {
        return '';
    }

    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html);
    $html = preg_replace('/javascript:/i', '', $html);

    return $html;
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
 * DB CONNECTION + REVIEW STORAGE
 */
try {
    $pdo = new PDO($dsn, $user, $pass, $options);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_code_reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            objective_log_id INT NOT NULL,
            marked_code_html MEDIUMTEXT NULL,
            final_grade DECIMAL(5,2) NULL,
            review_notes TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_objective_log_id (objective_log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_submission_evaluations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            objective_log_id BIGINT UNSIGNED NOT NULL,
            participant_id VARCHAR(100) NOT NULL,
            evaluator_version VARCHAR(50) NOT NULL,
            ai_model VARCHAR(100) NOT NULL,
            evaluation_status ENUM('pending','completed','failed','manual_review') NOT NULL DEFAULT 'pending',
            verdict ENUM('correct','partially_correct','incorrect','uncertain') NULL,
            uses_required_algorithm TINYINT(1) NULL,
            general_solution TINYINT(1) NULL,
            hardcoding_detected TINYINT(1) NULL,
            algorithm_score DECIMAL(5,2) NULL,
            generality_score DECIMAL(5,2) NULL,
            complexity_score DECIMAL(5,2) NULL,
            robustness_score DECIMAL(5,2) NULL,
            ai_evaluation_score DECIMAL(5,2) NULL,
            confidence DECIMAL(5,4) NULL,
            complexity_class VARCHAR(50) NULL,
            rationale_codes JSON NULL,
            evaluation_json LONGTEXT NULL,
            code_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ai_objective_log (objective_log_id),
            INDEX idx_ai_participant (participant_id),
            INDEX idx_ai_score (ai_evaluation_score),
            INDEX idx_ai_status (evaluation_status),
            INDEX idx_ai_verdict (verdict),
            INDEX idx_ai_code_hash (code_hash),
            CONSTRAINT fk_ai_evaluation_objective_log
                FOREIGN KEY (objective_log_id) REFERENCES objective_success_logs(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    die('Σφάλμα σύνδεσης με τη βάση: ' . escape($e->getMessage()));
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_ai_evaluation') {
    header('Content-Type: application/json; charset=utf-8');

    $objectiveLogId = (int)($_POST['objective_log_id'] ?? 0);
    if ($objectiveLogId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Μη έγκυρο objective log ID.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, user_id, code_snapshot,
            visible_test_passed, visible_test_output,
            hidden_test_1_passed, hidden_test_1_output,
            hidden_test_2_passed, hidden_test_2_output,
            decreasing_property_passed
            FROM objective_success_logs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $objectiveLogId]);
        $submission = $stmt->fetch();
        if (!$submission) throw new RuntimeException('Δεν βρέθηκε το submission.');

        $tests = [
            'visible_test_passed' => (int)$submission['visible_test_passed'] === 1,
            'visible_test_output' => (string)($submission['visible_test_output'] ?? ''),
            'hidden_test_1_passed' => (int)$submission['hidden_test_1_passed'] === 1,
            'hidden_test_1_output' => (string)($submission['hidden_test_1_output'] ?? ''),
            'hidden_test_2_passed' => (int)$submission['hidden_test_2_passed'] === 1,
            'hidden_test_2_output' => (string)($submission['hidden_test_2_output'] ?? ''),
            'decreasing_property_passed' => (int)$submission['decreasing_property_passed'] === 1,
        ];

        $evaluation = evaluateSubmissionWithAi($pdo, (string)$submission['code_snapshot'], $tests);
        $evaluationStatus = (float)$evaluation['confidence'] < 0.70 ? 'manual_review' : 'completed';
        $deterministicScore =
            ((int)$submission['visible_test_passed'] * 20) +
            ((int)$submission['hidden_test_1_passed'] * 20) +
            ((int)$submission['hidden_test_2_passed'] * 20) +
            ((int)$submission['decreasing_property_passed'] * 20);
        $aiWeightedContribution = round((float)$evaluation['total_score'] * 0.20, 2);
        $finalScore = min(100, round($deterministicScore + $aiWeightedContribution, 2));
        $hardcoded = !empty($evaluation['hardcoding_detected']);
        $completed = $deterministicScore === 80
            && $evaluationStatus === 'completed'
            && ($evaluation['verdict'] ?? '') === 'correct'
            && !empty($evaluation['uses_required_algorithm'])
            && !empty($evaluation['general_solution'])
            && !$hardcoded;

        $pdo->beginTransaction();
        $save = $pdo->prepare("INSERT INTO ai_submission_evaluations (
            objective_log_id, participant_id, evaluator_version, ai_model,
            evaluation_status, verdict, uses_required_algorithm, general_solution,
            hardcoding_detected, algorithm_score, generality_score, complexity_score,
            robustness_score, ai_evaluation_score, confidence, complexity_class,
            rationale_codes, evaluation_json, code_hash
        ) VALUES (
            :objective_log_id, :participant_id, :evaluator_version, :ai_model,
            :evaluation_status, :verdict, :uses_required_algorithm, :general_solution,
            :hardcoding_detected, :algorithm_score, :generality_score, :complexity_score,
            :robustness_score, :ai_evaluation_score, :confidence, :complexity_class,
            :rationale_codes, :evaluation_json, :code_hash
        ) ON DUPLICATE KEY UPDATE
            participant_id=VALUES(participant_id), evaluator_version=VALUES(evaluator_version),
            ai_model=VALUES(ai_model), evaluation_status=VALUES(evaluation_status),
            verdict=VALUES(verdict), uses_required_algorithm=VALUES(uses_required_algorithm),
            general_solution=VALUES(general_solution), hardcoding_detected=VALUES(hardcoding_detected),
            algorithm_score=VALUES(algorithm_score), generality_score=VALUES(generality_score),
            complexity_score=VALUES(complexity_score), robustness_score=VALUES(robustness_score),
            ai_evaluation_score=VALUES(ai_evaluation_score), confidence=VALUES(confidence),
            complexity_class=VALUES(complexity_class), rationale_codes=VALUES(rationale_codes),
            evaluation_json=VALUES(evaluation_json), code_hash=VALUES(code_hash), updated_at=NOW()");
        $save->execute([
            ':objective_log_id'=>$objectiveLogId,
            ':participant_id'=>(string)$submission['user_id'],
            ':evaluator_version'=>(string)$evaluation['evaluator_version'],
            ':ai_model'=>(string)$evaluation['model'],
            ':evaluation_status'=>$evaluationStatus,
            ':verdict'=>(string)$evaluation['verdict'],
            ':uses_required_algorithm'=>!empty($evaluation['uses_required_algorithm']) ? 1 : 0,
            ':general_solution'=>!empty($evaluation['general_solution']) ? 1 : 0,
            ':hardcoding_detected'=>$hardcoded ? 1 : 0,
            ':algorithm_score'=>(float)$evaluation['algorithm_score'],
            ':generality_score'=>(float)$evaluation['generality_score'],
            ':complexity_score'=>(float)$evaluation['complexity_score'],
            ':robustness_score'=>(float)$evaluation['robustness_score'],
            ':ai_evaluation_score'=>(float)$evaluation['total_score'],
            ':confidence'=>(float)$evaluation['confidence'],
            ':complexity_class'=>(string)$evaluation['complexity_class'],
            ':rationale_codes'=>json_encode($evaluation['rationale_codes'] ?? [], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':evaluation_json'=>json_encode($evaluation, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':code_hash'=>(string)$evaluation['code_hash'],
        ]);

        $update = $pdo->prepare("UPDATE objective_success_logs SET
            hardcoded_solution_detected=:hardcoded,
            final_objective_success_score=:final_score,
            completed_successfully=:completed WHERE id=:id");
        $update->execute([':hardcoded'=>$hardcoded?1:0, ':final_score'=>$finalScore, ':completed'=>$completed?1:0, ':id'=>$objectiveLogId]);
        $pdo->commit();

        echo json_encode([
            'ok'=>true,
            'message'=>'Η AI αξιολόγηση ολοκληρώθηκε και αποθηκεύτηκε.',
            'evaluation'=>array_merge($evaluation, [
                'evaluation_status'=>$evaluationStatus,
                'ai_model'=>$evaluation['model'],
                'ai_evaluation_score'=>(float)$evaluation['total_score'],
                'deterministic_test_score'=>$deterministicScore,
                'ai_weighted_contribution'=>$aiWeightedContribution,
                'combined_objective_score'=>$finalScore,
                'was_cached'=>false,
                'updated_at'=>date('Y-m-d H:i:s'),
            ])
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ADMIN AI EVALUATOR] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
        try {
            $failure = $pdo->prepare("INSERT INTO ai_submission_evaluations (
                objective_log_id, participant_id, evaluator_version, ai_model,
                evaluation_status, evaluation_json, code_hash
            ) SELECT id, user_id, :version, :model, 'failed', :evaluation_json, SHA2(code_snapshot,256)
              FROM objective_success_logs WHERE id=:id
              ON DUPLICATE KEY UPDATE evaluation_status='failed',
                evaluation_json=VALUES(evaluation_json), updated_at=NOW()");
            $failure->execute([
                ':version'=>AI_EVALUATOR_VERSION, ':model'=>AI_EVALUATOR_MODEL,
                ':evaluation_json'=>json_encode(['status'=>'error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ':id'=>$objectiveLogId
            ]);
        } catch (Throwable $ignored) {
            error_log('[ADMIN AI EVALUATOR DB LOG ERROR] '.$ignored->getMessage());
        }
        echo json_encode(['ok'=>false,'message'=>'Αποτυχία AI evaluation.','details'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_code_review') {
    header('Content-Type: application/json; charset=utf-8');

    $objectiveLogId = (int)($_POST['objective_log_id'] ?? 0);
    $markedCodeHtml = normalizeReviewHtml($_POST['marked_code_html'] ?? '');
    $reviewNotes = trim((string)($_POST['review_notes'] ?? ''));
    $finalGradeRaw = trim((string)($_POST['final_grade'] ?? ''));

    if ($objectiveLogId <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Μη έγκυρο log ID.']);
        exit;
    }

    if ($finalGradeRaw === '' || !is_numeric($finalGradeRaw)) {
        echo json_encode(['ok' => false, 'message' => 'Συμπλήρωσε έγκυρο βαθμό admin.']);
        exit;
    }

    $finalGrade = (float)$finalGradeRaw;

    if ($finalGrade < 0 || $finalGrade > 100) {
        echo json_encode(['ok' => false, 'message' => 'Ο βαθμός admin πρέπει να είναι από 0 έως 100.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_code_reviews (
                objective_log_id,
                marked_code_html,
                final_grade,
                review_notes,
                updated_at
            ) VALUES (
                :objective_log_id,
                :marked_code_html,
                :final_grade,
                :review_notes,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                marked_code_html = VALUES(marked_code_html),
                final_grade = VALUES(final_grade),
                review_notes = VALUES(review_notes),
                updated_at = NOW()
        ");

        $stmt->execute([
            ':objective_log_id' => $objectiveLogId,
            ':marked_code_html' => $markedCodeHtml,
            ':final_grade' => $finalGrade,
            ':review_notes' => $reviewNotes,
        ]);

        $scoreStmt = $pdo->prepare("SELECT final_objective_success_score FROM objective_success_logs WHERE id = :id LIMIT 1");
        $scoreStmt->execute([':id' => $objectiveLogId]);
        $systemScore = (float)($scoreStmt->fetchColumn() ?: 0);
        $combinedGrade = ($systemScore + $finalGrade) / 2;

        echo json_encode([
            'ok' => true,
            'message' => 'Η αξιολόγηση αποθηκεύτηκε.',
            'final_grade' => number_format($finalGrade, 2, '.', ''),
            'combined_grade' => number_format($combinedGrade, 2, '.', ''),
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'message' => 'Σφάλμα αποθήκευσης στη βάση.']);
        exit;
    }
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

    $reviewsByLogId = [];
    $aiEvaluationsByLogId = [];
    $logIds = array_values(array_filter(array_map(static fn($log) => (int)$log['id'], $logs)));

    if (!empty($logIds)) {
        $placeholders = implode(',', array_fill(0, count($logIds), '?'));

        $reviewStmt = $pdo->prepare("
            SELECT
                objective_log_id,
                marked_code_html,
                final_grade,
                review_notes,
                updated_at
            FROM admin_code_reviews
            WHERE objective_log_id IN ($placeholders)
        ");

        $reviewStmt->execute($logIds);

        foreach ($reviewStmt->fetchAll() as $review) {
            $reviewsByLogId[(int)$review['objective_log_id']] = $review;
        }

        $aiStmt = $pdo->prepare("
            SELECT
                objective_log_id,
                participant_id,
                evaluation_status,
                evaluator_version,
                ai_model,
                verdict,
                uses_required_algorithm,
                general_solution,
                hardcoding_detected,
                complexity_class,
                algorithm_score,
                generality_score,
                complexity_score,
                robustness_score,
                ai_evaluation_score,
                confidence,
                rationale_codes,
                evaluation_json,
                updated_at
            FROM ai_submission_evaluations
            WHERE objective_log_id IN ($placeholders)
        ");
        $aiStmt->execute($logIds);

        foreach ($aiStmt->fetchAll() as $aiEvaluation) {
            $aiEvaluationsByLogId[(int)$aiEvaluation['objective_log_id']] = $aiEvaluation;
        }
    }

    $modalLogs = [];

    foreach ($logs as $log) {
        $logId = (int)$log['id'];
        $review = $reviewsByLogId[$logId] ?? null;
        $aiEvaluation = $aiEvaluationsByLogId[$logId] ?? null;

        $modalLogs[$logId] = [
            'log_id' => $logId,
            'user_id' => (string)($log['user_id'] ?? '-'),
            'condition' => (string)($log['condition'] ?? '-'),
            'task_id' => (string)($log['task_id'] ?? '-'),
            'code_snapshot' => (string)($log['code_snapshot'] ?? ''),
            'marked_code_html' => (string)($review['marked_code_html'] ?? ''),
            'final_grade' => isset($review['final_grade']) ? (string)$review['final_grade'] : '',
            'system_score' => (float)($log['final_objective_success_score'] ?? 0),
            'combined_grade' => isset($review['final_grade'])
                ? number_format((((float)($log['final_objective_success_score'] ?? 0)) + (float)$review['final_grade']) / 2, 2, '.', '')
                : '',
            'review_notes' => (string)($review['review_notes'] ?? ''),
            'review_updated_at' => (string)($review['updated_at'] ?? ''),
            'review_status' => $review ? 'reviewed' : 'not_reviewed',
            'failure_reason' => objectiveFailureReason($log),
            'ai_evaluation' => $aiEvaluation ? [
                'evaluation_status' => (string)($aiEvaluation['evaluation_status'] ?? ''),
                'evaluator_version' => (string)($aiEvaluation['evaluator_version'] ?? ''),
                'ai_model' => (string)($aiEvaluation['ai_model'] ?? ''),
                'verdict' => (string)($aiEvaluation['verdict'] ?? ''),
                'uses_required_algorithm' => isset($aiEvaluation['uses_required_algorithm']) ? (bool)$aiEvaluation['uses_required_algorithm'] : null,
                'general_solution' => isset($aiEvaluation['general_solution']) ? (bool)$aiEvaluation['general_solution'] : null,
                'hardcoding_detected' => isset($aiEvaluation['hardcoding_detected']) ? (bool)$aiEvaluation['hardcoding_detected'] : null,
                'complexity_class' => (string)($aiEvaluation['complexity_class'] ?? ''),
                'algorithm_score' => $aiEvaluation['algorithm_score'],
                'generality_score' => $aiEvaluation['generality_score'],
                'complexity_score' => $aiEvaluation['complexity_score'],
                'robustness_score' => $aiEvaluation['robustness_score'],
                'ai_evaluation_score' => $aiEvaluation['ai_evaluation_score'],
                'confidence' => $aiEvaluation['confidence'],
                'deterministic_test_score' => ((int)$log['visible_test_passed'] + (int)$log['hidden_test_1_passed'] + (int)$log['hidden_test_2_passed'] + (int)$log['decreasing_property_passed']) * 20,
                'ai_weighted_contribution' => round((float)($aiEvaluation['ai_evaluation_score'] ?? 0) * 0.20, 2),
                'combined_objective_score' => (float)($log['final_objective_success_score'] ?? 0),
                'rationale_codes' => json_decode((string)($aiEvaluation['rationale_codes'] ?? '[]'), true) ?: [],
                'was_cached' => false,
                'evaluation_error' => (string)((json_decode((string)($aiEvaluation['evaluation_json'] ?? ''), true)['error'] ?? '')),
                'updated_at' => (string)($aiEvaluation['updated_at'] ?? ''),
            ] : null,
        ];
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

    body.modal-open {
      overflow: hidden;
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

    .code-snapshot-btn {
      width: 420px;
      text-align: left;
      cursor: pointer;
      transition: border-color .18s ease, background .18s ease, transform .18s ease;
    }

    .code-snapshot-btn:hover {
      background: rgba(91,140,255,.10);
      border-color: rgba(91,140,255,.34);
      transform: translateY(-1px);
    }

    .code-snapshot-hint {
      display: block;
      margin-top: 6px;
      color: #93c5fd;
      font-family: "Manrope", system-ui, sans-serif;
      font-size: .68rem;
      font-weight: 800;
      letter-spacing: .02em;
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

    .code-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 5000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.2rem;
      background: rgba(3, 8, 20, 0.62);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      opacity: 0;
      visibility: hidden;
      transition: opacity .25s ease, visibility .25s ease;
    }

    .code-modal-overlay.active {
      opacity: 1;
      visibility: visible;
    }

    .code-modal {
      width: min(96vw, 980px);
      max-height: 88vh;
      display: flex;
      flex-direction: column;
      border-radius: 22px;
      border: 1px solid rgba(148,163,184,.22);
      background:
        radial-gradient(circle at top left, rgba(91,140,255,.12), transparent 28%),
        rgba(10, 18, 34, .98);
      box-shadow: 0 30px 80px rgba(0,0,0,.46);
      overflow: hidden;
      transform: translateY(12px) scale(.98);
      transition: transform .25s ease;
    }

    .code-modal-overlay.active .code-modal {
      transform: translateY(0) scale(1);
    }

    .code-modal-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 16px 18px;
      border-bottom: 1px solid rgba(148,163,184,.16);
      background: rgba(255,255,255,.02);
    }

    .code-modal-title {
      font-size: 1rem;
      font-weight: 900;
      color: var(--text);
      margin: 0;
    }

    .code-modal-subtitle {
      margin-top: 4px;
      font-size: .76rem;
      color: var(--text-muted);
    }

    .code-modal-close {
      width: 38px;
      height: 38px;
      border-radius: 12px;
      border: 1px solid rgba(148,163,184,.2);
      background: rgba(255,255,255,.04);
      color: var(--text-soft);
      cursor: pointer;
      font-size: 1.25rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background .18s ease, color .18s ease, transform .18s ease;
      flex: 0 0 auto;
    }

    .code-modal-close:hover {
      background: rgba(239,68,68,.12);
      color: #fecaca;
      transform: translateY(-1px);
    }

    .code-modal-body {
      padding: 16px 18px 18px;
      overflow: auto;
      min-height: 260px;
    }

    .code-review-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .code-review-tools,
    .code-review-save {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .review-btn {
      min-height: 36px;
      border-radius: 10px;
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.04);
      color: var(--text);
      padding: 0 12px;
      cursor: pointer;
      font-size: .76rem;
      font-weight: 800;
      transition: transform .18s ease, border-color .18s ease, background .18s ease;
    }

    .review-btn:hover {
      transform: translateY(-1px);
      border-color: rgba(91,140,255,.38);
      background: rgba(91,140,255,.10);
    }

    .review-btn.correct {
      background: rgba(34,197,94,.14);
      border-color: rgba(34,197,94,.28);
      color: #bbf7d0;
    }

    .review-btn.wrong {
      background: rgba(239,68,68,.14);
      border-color: rgba(239,68,68,.28);
      color: #fecaca;
    }

    .review-btn.save {
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      border-color: rgba(91,140,255,.28);
      color: #fff;
      box-shadow: 0 10px 24px rgba(59,130,246,.20);
    }

    .review-grade-input {
      width: 130px;
      min-height: 36px;
      border-radius: 10px;
      border: 1px solid rgba(148,163,184,.22);
      background: rgba(2,6,23,.72);
      color: var(--text);
      padding: 0 10px;
      font: inherit;
      font-size: .78rem;
      font-weight: 800;
      outline: none;
    }

    .review-grade-input:focus,
    .review-notes:focus {
      border-color: rgba(91,140,255,.55);
      box-shadow: 0 0 0 4px rgba(91,140,255,.10);
    }

    .review-notes {
      width: 100%;
      min-height: 74px;
      margin-top: 12px;
      border-radius: 14px;
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(2,6,23,.62);
      color: var(--text);
      padding: 10px 12px;
      font: inherit;
      font-size: .8rem;
      line-height: 1.55;
      resize: vertical;
      outline: none;
    }

    .review-help {
      margin-bottom: 10px;
      color: var(--text-muted);
      font-size: .74rem;
      line-height: 1.55;
    }

    .review-status {
      min-height: 18px;
      color: var(--text-muted);
      font-size: .74rem;
      font-weight: 700;
    }

    .review-status.ok {
      color: #86efac;
    }

    .review-status.error {
      color: #fca5a5;
    }

    .code-modal-pre[contenteditable="true"] {
      outline: none;
      cursor: text;
    }

    .code-modal-pre[contenteditable="true"]:focus {
      border-color: rgba(91,140,255,.42);
      box-shadow: 0 0 0 4px rgba(91,140,255,.08);
    }

    .code-modal-pre {
      margin: 0;
      min-height: 260px;
      white-space: pre-wrap;
      word-break: break-word;
      font-family: "JetBrains Mono", "Fira Code", Consolas, monospace;
      font-size: .82rem;
      line-height: 1.65;
      color: #dbeafe;
      background: rgba(2,6,23,.72);
      border: 1px solid rgba(148,163,184,.14);
      border-radius: 16px;
      padding: 14px;
      overflow: auto;
    }


    .objective-controls {
      display: grid;
      grid-template-columns: minmax(220px, 1.4fr) repeat(4, minmax(150px, .8fr));
      gap: 10px;
      margin-bottom: 12px;
    }

    .objective-control {
      min-height: 40px;
      border-radius: 11px;
      border: 1px solid rgba(148,163,184,.18);
      background: rgba(255,255,255,.035);
      color: var(--text);
      padding: 0 11px;
      outline: none;
      font: inherit;
      font-size: .76rem;
      font-weight: 700;
    }

    .objective-control:focus {
      border-color: rgba(91,140,255,.48);
      box-shadow: 0 0 0 4px rgba(91,140,255,.08);
    }

    .objective-control option {
      background: #10182c;
      color: var(--text);
    }

    .objective-visible-count {
      margin: 2px 0 10px;
      color: var(--text-muted);
      font-size: .72rem;
      font-weight: 700;
    }

    .objective-main-table {
      min-width: 1480px;
      table-layout: auto;
    }

    .objective-main-table thead th,
    .objective-main-table tbody td {
      white-space: nowrap;
      padding-left: 16px;
      padding-right: 16px;
    }

    .objective-main-table thead th:nth-child(1),
    .objective-main-table tbody td:nth-child(1) { min-width: 145px; }
    .objective-main-table thead th:nth-child(2),
    .objective-main-table tbody td:nth-child(2) { min-width: 90px; }
    .objective-main-table thead th:nth-child(3),
    .objective-main-table tbody td:nth-child(3),
    .objective-main-table thead th:nth-child(4),
    .objective-main-table tbody td:nth-child(4),
    .objective-main-table thead th:nth-child(5),
    .objective-main-table tbody td:nth-child(5),
    .objective-main-table thead th:nth-child(6),
    .objective-main-table tbody td:nth-child(6) { min-width: 105px; }
    .objective-main-table thead th:nth-child(7),
    .objective-main-table tbody td:nth-child(7) { min-width: 115px; }
    .objective-main-table thead th:nth-child(8),
    .objective-main-table tbody td:nth-child(8) { min-width: 95px; }
    .objective-main-table thead th:nth-child(9),
    .objective-main-table tbody td:nth-child(9) { min-width: 120px; }
    .objective-main-table thead th:nth-child(10),
    .objective-main-table tbody td:nth-child(10) { min-width: 185px; }
    .objective-main-table thead th:nth-child(11),
    .objective-main-table tbody td:nth-child(11) { min-width: 145px; }
    .objective-main-table thead th:nth-child(12),
    .objective-main-table tbody td:nth-child(12) { min-width: 165px; }

    .objective-search-only {
      grid-template-columns: minmax(280px, 520px);
      justify-content: start;
    }

    .objective-search-only .objective-control {
      width: 100%;
    }

    .objective-main-table thead th,
    .objective-main-table tbody td {
      text-align: center;
    }

    .objective-main-table thead th:first-child,
    .objective-main-table tbody td:first-child,
    .objective-main-table thead th:nth-child(2),
    .objective-main-table tbody td:nth-child(2),
    .objective-main-table thead th:last-child,
    .objective-main-table tbody td:last-child {
      text-align: left;
    }

    .sortable-header {
      cursor: pointer;
      user-select: none;
    }

    .sortable-header::after {
      content: '↕';
      margin-left: 6px;
      color: var(--text-muted);
      font-size: .68rem;
    }

    .objective-row {
      cursor: pointer;
    }

    .objective-row.active-row {
      background: rgba(91,140,255,.10);
    }

    .objective-details-row {
      display: table-row;
    }

    .objective-details-row > td {
      padding: 0 !important;
      border-bottom: 0 !important;
      white-space: normal !important;
    }

    .objective-details-outer {
      display: grid;
      grid-template-rows: 0fr;
      opacity: 0;
      transition: grid-template-rows .3s ease, opacity .22s ease;
    }

    .objective-details-inner {
      overflow: hidden;
      min-height: 0;
    }

    .objective-details-row.open .objective-details-outer {
      grid-template-rows: 1fr;
      opacity: 1;
    }

    .objective-details-panel {
      padding: 14px;
      background: rgba(2,6,23,.18);
      border-top: 1px solid rgba(148,163,184,.10);
    }

    .objective-summary-strip {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 14px;
    }


    .objective-outcome-visual {
      display: grid;
      grid-template-columns: 220px minmax(0, 1fr) minmax(220px, 280px);
      gap: 18px;
      align-items: center;
      padding: 16px;
      margin-bottom: 14px;
      border: 1px solid rgba(148,163,184,.16);
      border-radius: 18px;
      background:
        radial-gradient(circle at 12% 20%, rgba(91,140,255,.13), transparent 34%),
        rgba(255,255,255,.025);
      overflow: hidden;
    }

    .objective-score-ring-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .objective-score-ring {
      --score-color: #60a5fa;
      width: 146px;
      height: 146px;
      display: grid;
      place-items: center;
      position: relative;
      border-radius: 50%;
      box-shadow: 0 18px 36px rgba(0,0,0,.22), inset 0 0 0 1px rgba(255,255,255,.04);
    }

    .objective-score-ring-svg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      transform: rotate(-90deg);
      overflow: visible;
    }

    .objective-score-ring-track,
    .objective-score-ring-progress {
      fill: none;
      stroke-width: 12;
    }

    .objective-score-ring-track {
      stroke: rgba(148,163,184,.14);
    }

    .objective-score-ring-progress {
      stroke: var(--score-color);
      stroke-linecap: round;
      stroke-dasharray: 339.292;
      stroke-dashoffset: 339.292;
      transition: stroke-dashoffset 1.05s cubic-bezier(.22, 1, .36, 1);
      filter: drop-shadow(0 0 5px color-mix(in srgb, var(--score-color) 45%, transparent));
    }

    .objective-score-ring::before {
      content: '';
      position: absolute;
      inset: 14px;
      border-radius: 50%;
      background: rgba(10,18,34,.97);
      border: 1px solid rgba(148,163,184,.14);
    }

    .objective-score-ring-content {
      position: relative;
      z-index: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
    }

    .objective-score-ring-value {
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 900;
      color: var(--score-color);
      text-shadow: 0 0 18px rgba(255,255,255,.10);
    }

    .objective-score-ring-label {
      color: var(--text-muted);
      font-size: .66rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .08em;
    }

    .objective-outcome-content {
      min-width: 0;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }


    .objective-grade-comparison {
      min-width: 0;
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
    }

    .objective-grade-card {
      min-width: 0;
      border: 1px solid rgba(148,163,184,.16);
      border-radius: 14px;
      padding: 12px 13px;
      background: rgba(2,6,23,.34);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.025);
    }

    .objective-grade-card.system {
      border-color: rgba(91,140,255,.24);
      background: rgba(91,140,255,.08);
    }

    .objective-grade-card.admin {
      border-color: rgba(168,85,247,.24);
      background: rgba(168,85,247,.08);
    }

    .objective-grade-card.final {
      border-color: rgba(34,197,94,.26);
      background: rgba(34,197,94,.09);
    }

    .objective-grade-label {
      color: var(--text-muted);
      font-size: .64rem;
      font-weight: 850;
      text-transform: uppercase;
      letter-spacing: .07em;
      margin-bottom: 6px;
    }

    .objective-grade-value {
      display: flex;
      align-items: baseline;
      gap: 5px;
      color: var(--text);
      font-size: 1.15rem;
      line-height: 1;
      font-weight: 900;
    }

    .objective-grade-value span {
      color: var(--text-muted);
      font-size: .68rem;
      font-weight: 800;
    }

    .objective-grade-missing {
      color: var(--text-soft);
      font-size: .78rem;
      font-weight: 800;
    }

    .overall-outcome-badge {
      width: fit-content;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 34px;
      padding: 0 13px;
      border-radius: 999px;
      border: 1px solid transparent;
      font-size: .76rem;
      font-weight: 900;
      letter-spacing: .01em;
    }

    .overall-outcome-badge.successful {
      color: #bbf7d0;
      background: rgba(34,197,94,.14);
      border-color: rgba(34,197,94,.30);
      box-shadow: 0 10px 24px rgba(34,197,94,.08);
    }

    .overall-outcome-badge.partial {
      color: #fde68a;
      background: rgba(245,158,11,.14);
      border-color: rgba(245,158,11,.30);
      box-shadow: 0 10px 24px rgba(245,158,11,.08);
    }

    .overall-outcome-badge.failed {
      color: #fecaca;
      background: rgba(239,68,68,.14);
      border-color: rgba(239,68,68,.30);
      box-shadow: 0 10px 24px rgba(239,68,68,.08);
    }

    .objective-outcome-title {
      color: var(--text);
      font-size: .92rem;
      font-weight: 900;
    }

    .objective-outcome-subtitle {
      color: var(--text-muted);
      font-size: .74rem;
      line-height: 1.55;
    }

    .objective-test-track {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px;
      position: relative;
    }

    .objective-test-node {
      min-width: 0;
      border-radius: 12px;
      border: 1px solid rgba(239,68,68,.18);
      background: rgba(239,68,68,.07);
      padding: 9px 8px;
      text-align: center;
    }

    .objective-test-node.pass {
      border-color: rgba(34,197,94,.22);
      background: rgba(34,197,94,.08);
    }

    .objective-test-node-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      margin: 0 auto 6px;
      background: #ef4444;
      box-shadow: 0 0 0 4px rgba(239,68,68,.10);
    }

    .objective-test-node.pass .objective-test-node-dot {
      background: #22c55e;
      box-shadow: 0 0 0 4px rgba(34,197,94,.10);
    }

    .objective-test-node-label {
      color: var(--text-soft);
      font-size: .64rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .objective-score-ring {
      transition: transform .45s ease, opacity .45s ease;
      transform: scale(.78) rotate(-8deg);
      opacity: 0;
    }

    .objective-outcome-content,
    .objective-grade-comparison,
    .objective-summary-item,
    .test-progress-item,
    .objective-detail-section {
      opacity: 0;
      transform: translateY(12px);
    }

    .objective-details-panel.results-animate-in .objective-score-ring {
      transform: scale(1) rotate(0deg);
      opacity: 1;
    }

    .objective-details-panel.results-animate-in .objective-outcome-content {
      animation: objectiveFadeUp .55s .12s ease forwards;
    }

    .objective-details-panel.results-animate-in .objective-grade-comparison {
      animation: objectiveFadeUp .55s .20s ease forwards;
    }

    .objective-details-panel.results-animate-in .overall-outcome-badge {
      animation: objectiveBadgePop .55s .28s cubic-bezier(.22, 1.35, .36, 1) both;
    }

    .objective-details-panel.results-animate-in .objective-test-node {
      animation: objectiveNodeIn .46s cubic-bezier(.22, 1, .36, 1) both;
    }

    .objective-details-panel.results-animate-in .objective-test-node:nth-child(1) { animation-delay: .30s; }
    .objective-details-panel.results-animate-in .objective-test-node:nth-child(2) { animation-delay: .38s; }
    .objective-details-panel.results-animate-in .objective-test-node:nth-child(3) { animation-delay: .46s; }
    .objective-details-panel.results-animate-in .objective-test-node:nth-child(4) { animation-delay: .54s; }

    .objective-details-panel.results-animate-in .objective-summary-item {
      animation: objectiveFadeUp .46s ease forwards;
    }

    .objective-details-panel.results-animate-in .objective-summary-item:nth-child(1) { animation-delay: .36s; }
    .objective-details-panel.results-animate-in .objective-summary-item:nth-child(2) { animation-delay: .42s; }
    .objective-details-panel.results-animate-in .objective-summary-item:nth-child(3) { animation-delay: .48s; }
    .objective-details-panel.results-animate-in .objective-summary-item:nth-child(4) { animation-delay: .54s; }
    .objective-details-panel.results-animate-in .objective-summary-item:nth-child(5) { animation-delay: .60s; }

    .objective-details-panel.results-animate-in .test-progress-item {
      animation: objectiveFadeUp .42s ease forwards;
    }

    .objective-details-panel.results-animate-in .test-progress-item:nth-child(1) { animation-delay: .48s; }
    .objective-details-panel.results-animate-in .test-progress-item:nth-child(2) { animation-delay: .54s; }
    .objective-details-panel.results-animate-in .test-progress-item:nth-child(3) { animation-delay: .60s; }
    .objective-details-panel.results-animate-in .test-progress-item:nth-child(4) { animation-delay: .66s; }

    .objective-details-panel.results-animate-in .objective-detail-section {
      animation: objectiveFadeUp .45s ease forwards;
    }

    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(1) { animation-delay: .56s; }
    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(2) { animation-delay: .62s; }
    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(3) { animation-delay: .68s; }
    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(4) { animation-delay: .74s; }
    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(5) { animation-delay: .80s; }
    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(6) { animation-delay: .86s; }
    .objective-details-panel.results-animate-in .objective-detail-section:nth-child(7) { animation-delay: .92s; }

    @keyframes objectiveFadeUp {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes objectiveBadgePop {
      from { opacity: 0; transform: scale(.78); }
      to { opacity: 1; transform: scale(1); }
    }

    @keyframes objectiveNodeIn {
      from { opacity: 0; transform: translateY(10px) scale(.92); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    @media (prefers-reduced-motion: reduce) {
      .objective-score-ring,
      .objective-outcome-content,
      .objective-grade-comparison,
      .objective-summary-item,
      .test-progress-item,
      .objective-detail-section,
      .overall-outcome-badge,
      .objective-test-node {
        animation: none !important;
        transition: none !important;
        opacity: 1 !important;
        transform: none !important;
      }

      .objective-score-ring-progress {
        transition: none !important;
      }
    }

    @media (max-width: 1050px) {
      .objective-outcome-visual {
        grid-template-columns: 190px minmax(0, 1fr);
      }

      .objective-grade-comparison {
        grid-column: 1 / -1;
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 760px) {
      .objective-outcome-visual {
        grid-template-columns: 1fr;
      }

      .objective-grade-comparison {
        grid-column: auto;
        grid-template-columns: 1fr;
      }

      .objective-test-track {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    .objective-summary-item {
      min-width: 0;
      border-bottom: 2px solid rgba(148,163,184,.15);
      padding: 4px 4px 10px;
    }

    .objective-summary-label {
      color: var(--text-muted);
      font-size: .66rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      margin-bottom: 5px;
    }

    .objective-summary-value {
      color: var(--text);
      font-size: .82rem;
      font-weight: 850;
      overflow-wrap: anywhere;
    }

    .test-progress {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px;
      margin: 10px 0 16px;
    }

    .test-progress-item {
      min-width: 0;
    }

    .test-progress-label {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 6px;
      color: var(--text-soft);
      font-size: .67rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .test-progress-state {
      color: #fca5a5;
      font-size: .62rem;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .test-progress-item.pass .test-progress-state {
      color: #86efac;
    }

    .test-progress-segment {
      height: 10px;
      border-radius: 999px;
      background: rgba(239,68,68,.55);
    }

    .test-progress-item.pass .test-progress-segment {
      background: rgba(34,197,94,.72);
    }

    .objective-details-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .objective-detail-section {
      min-width: 0;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 12px;
      background: rgba(255,255,255,.025);
    }

    .objective-detail-section.full {
      grid-column: 1 / -1;
    }

    .objective-detail-title {
      font-size: .78rem;
      font-weight: 850;
      color: var(--text);
      margin-bottom: 8px;
    }

    .objective-detail-meta {
      color: var(--text-muted);
      font-size: .7rem;
      line-height: 1.5;
      margin-bottom: 8px;
    }

    .objective-details-panel .output-box,
    .objective-details-panel .code-box {
      width: 100%;
      max-width: none;
      max-height: 190px;
    }

    .review-status-badge.reviewed {
      background: var(--success-soft);
      color: #bbf7d0;
      border-color: rgba(34,197,94,.22);
    }

    .review-status-badge.not-reviewed {
      background: rgba(148,163,184,.10);
      color: var(--text-soft);
      border-color: rgba(148,163,184,.18);
    }

    .review-status-badge.needs-attention {
      background: var(--warning-soft);
      color: #fde68a;
      border-color: rgba(245,158,11,.22);
    }

    @media (max-width: 1180px) {
      .objective-controls {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .objective-controls .objective-control:first-child {
        grid-column: 1 / -1;
      }

      .objective-summary-strip {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
    }

    @media (max-width: 760px) {
      .objective-controls,
      .objective-details-grid,
      .objective-summary-strip {
        grid-template-columns: 1fr;
      }

      .objective-controls .objective-control:first-child {
        grid-column: auto;
      }
    }

    .sticky-header-clone {
      position: fixed;
      left: 0;
      top: 70px;
      width: 100%;
      overflow: hidden;
      z-index: 30;
      display: none;
      pointer-events: none;
      border-left: 1px solid var(--border);
      border-right: 1px solid var(--border);
      background: var(--surface-2);
      box-shadow: 0 12px 28px rgba(0,0,0,.24);
    }

    .sticky-header-clone.visible {
      display: block;
    }

    body.modal-open .sticky-header-clone {
      display: none !important;
    }

    .sticky-header-clone table {
      border-collapse: collapse;
      margin: 0;
    }

    .sticky-header-clone .data-table thead th {
      position: static !important;
      top: auto !important;
      z-index: auto !important;
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

      .code-snapshot-btn {
        width: 320px;
      }

      .code-modal-overlay {
        padding: .75rem;
      }

      .code-modal {
        max-height: 90vh;
      }

      .code-modal-header {
        padding: 14px;
      }

      .code-modal-body {
        padding: 14px;
      }

      .code-modal-pre {
        font-size: .76rem;
      }
    }
  

    /* Keep the expanded participant details inside the visible dashboard frame,
       even when the main results table is wider and horizontally scrollable. */
    .objective-details-row > td {
      position: relative;
      overflow: visible;
    }

    .objective-details-row .objective-details-outer,
    .objective-details-row .objective-details-inner {
      min-width: 0;
      max-width: 100%;
    }

    .objective-details-panel {
      position: sticky;
      left: 0;
      width: calc(100vw - var(--sidebar-width) - 64px);
      max-width: calc(100vw - var(--sidebar-width) - 64px);
      min-width: 0;
      overflow: hidden;
      box-sizing: border-box;
    }

    .objective-summary-strip,
    .test-progress,
    .objective-details-grid,
    .objective-detail-section,
    .objective-detail-meta,
    .objective-details-panel .output-box,
    .objective-details-panel .code-box {
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .objective-detail-meta,
    .objective-summary-value,
    .objective-details-panel .output-box,
    .objective-details-panel .code-box {
      white-space: normal;
      overflow-wrap: anywhere;
      word-break: break-word;
    }

    .objective-details-panel .output-box,
    .objective-details-panel .code-box {
      overflow-x: auto;
    }

    @media (max-width: 1180px) {
      .objective-details-panel {
        width: calc(100vw - var(--sidebar-width) - 48px);
        max-width: calc(100vw - var(--sidebar-width) - 48px);
      }

      .objective-details-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 1024px) {
      .objective-details-panel {
        width: calc(100vw - 36px);
        max-width: calc(100vw - 36px);
      }
    }

    @media (max-width: 640px) {
      .objective-details-panel {
        width: calc(100vw - 24px);
        max-width: calc(100vw - 24px);
        padding: 10px;
      }

      .test-progress {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }


    /* Objective details modal: replaces inline row expansion without changing data or actions. */
    .objective-details-row {
      display: none !important;
    }

    .objective-details-modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 4000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.2rem;
      background: rgba(3, 8, 20, 0.62);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      opacity: 0;
      visibility: hidden;
      transition: opacity .25s ease, visibility .25s ease;
    }

    .objective-details-modal-overlay.active {
      opacity: 1;
      visibility: visible;
    }

    .objective-details-modal {
      width: min(96vw, 1180px);
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      border-radius: 22px;
      border: 1px solid rgba(148,163,184,.22);
      background:
        radial-gradient(circle at top left, rgba(91,140,255,.12), transparent 28%),
        rgba(10,18,34,.98);
      box-shadow: 0 30px 80px rgba(0,0,0,.46);
      overflow: hidden;
      transform: translateY(12px) scale(.98);
      transition: transform .25s ease;
    }

    .objective-details-modal-overlay.active .objective-details-modal {
      transform: translateY(0) scale(1);
    }

    .objective-details-modal-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 16px 18px;
      border-bottom: 1px solid rgba(148,163,184,.16);
      background: rgba(255,255,255,.02);
      flex: 0 0 auto;
    }

    .objective-details-modal-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 900;
      color: var(--text);
    }

    .objective-details-modal-subtitle {
      margin-top: 4px;
      font-size: .76rem;
      color: var(--text-muted);
    }

    .objective-details-modal-close {
      width: 36px;
      height: 36px;
      border-radius: 11px;
      border: 1px solid rgba(148,163,184,.2);
      background: rgba(255,255,255,.04);
      color: var(--text-soft);
      cursor: pointer;
      font-size: 1.18rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
    }

    .objective-details-modal-close:hover {
      background: rgba(239,68,68,.12);
      color: #fecaca;
    }

    .objective-details-modal-body {
      padding: 0;
      overflow: auto;
      min-height: 260px;
    }

    .objective-details-modal-body .objective-details-panel {
      position: static !important;
      width: 100% !important;
      max-width: 100% !important;
      min-width: 0 !important;
      overflow: visible !important;
      border-top: 0;
      background: transparent;
      padding: 16px 18px 18px;
    }

    .objective-details-modal-body .objective-details-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    @media (max-width: 760px) {
      .objective-details-modal-overlay { padding: .75rem; }
      .objective-details-modal { max-height: 92vh; }
      .objective-details-modal-header { padding: 14px; }
      .objective-details-modal-body .objective-details-panel { padding: 12px; }
      .objective-details-modal-body .objective-details-grid,
      .objective-details-modal-body .objective-summary-strip { grid-template-columns: 1fr; }
      .objective-details-modal-body .test-progress { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }


    .ai-evaluator-section {
      border-color: rgba(139,92,246,.28);
      background:
        radial-gradient(circle at top left, rgba(139,92,246,.10), transparent 34%),
        rgba(255,255,255,.025);
    }

    .ai-evaluator-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }

    .ai-evaluator-run-btn {
      background: linear-gradient(180deg, rgba(139,92,246,.92), rgba(99,102,241,.92));
      border-color: rgba(167,139,250,.34);
      color: #fff;
      box-shadow: 0 10px 24px rgba(99,102,241,.18);
    }

    .ai-evaluator-run-btn:disabled {
      opacity: .65;
      cursor: wait;
      transform: none;
    }

    .ai-evaluator-status {
      min-height: 20px;
      margin: 8px 0 12px;
      color: var(--text-muted);
      font-size: .74rem;
      font-weight: 700;
    }

    .ai-evaluator-status.ok {
      color: #86efac;
    }

    .ai-evaluator-status.error {
      color: #fca5a5;
    }

    .ai-evaluator-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 12px;
    }

    .ai-evaluator-metric {
      min-width: 0;
      border: 1px solid rgba(148,163,184,.16);
      border-radius: 13px;
      padding: 11px 12px;
      background: rgba(2,6,23,.34);
    }

    .ai-evaluator-metric span {
      display: block;
      color: var(--text-muted);
      font-size: .64rem;
      font-weight: 850;
      text-transform: uppercase;
      letter-spacing: .06em;
      margin-bottom: 6px;
    }

    .ai-evaluator-metric strong {
      color: var(--text);
      font-size: .92rem;
      overflow-wrap: anywhere;
    }

    .ai-evaluator-flags,
    .ai-rationale-codes {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 10px;
    }

    .ai-evaluator-score-breakdown {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px;
      margin-top: 12px;
    }

    .ai-evaluator-score-breakdown > div {
      border-bottom: 2px solid rgba(139,92,246,.24);
      padding: 6px 4px 9px;
      color: var(--text-muted);
      font-size: .7rem;
    }

    .ai-evaluator-score-breakdown strong {
      display: block;
      color: var(--text);
      margin-top: 4px;
      font-size: .82rem;
    }

    .ai-evaluator-meta {
      margin-top: 12px;
      margin-bottom: 0;
    }

    .ai-evaluator-empty {
      margin-top: 4px;
    }

    @media (max-width: 760px) {
      .ai-evaluator-grid,
      .ai-evaluator-score-breakdown {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
        <a class="sidebar-link" href="upload_video.php">Available Videos</a>
        <a class="sidebar-link" href="quest_admin.php">Questionnaires</a>
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

          <div class="objective-controls objective-search-only" id="objectiveControls">
            <input type="search" class="objective-control" id="objectiveSearch" placeholder="Αναζήτηση Participant ID...">
          </div>

          <div class="objective-visible-count" id="objectiveVisibleCount">Εμφανίζονται <?= count($logs) ?> από <?= count($logs) ?> logs</div>

          <div class="table-wrap objective-table-wrap" id="objectiveTableWrap">
            <?php if (empty($logs)): ?>
              <div class="empty-state" style="margin: 12px;">
                Δεν βρέθηκαν objective success logs για αυτό το filter.
              </div>
            <?php else: ?>
              <table class="data-table objective-main-table" id="objectiveSuccessTable">
                <thead>
                  <tr>
                    <th class="sortable-header" data-sort-key="participant">Participant ID</th>
                    <th>Group</th>
                    <th>Visible</th>
                    <th>Hidden 1</th>
                    <th>Hidden 2</th>
                    <th>Decreasing</th>
                    <th>Hardcoded</th>
                    <th class="sortable-header" data-sort-key="score">Score</th>
                    <th class="sortable-header" data-sort-key="completed">Completed</th>
                    <th>Failure Reason</th>
                    <th>Review Status</th>
                    <th class="sortable-header" data-sort-key="created">Created</th>
                  </tr>
                </thead>

                <tbody id="objectiveTableBody">
                  <?php foreach ($logs as $log): ?>
                    <?php
                      $logId = (int)$log['id'];
                      $condition = (string)($log['condition'] ?? '');
                      $conditionClass = $condition === 'video_tutorial' ? 'video' : 'ai';
                      $groupLabel = $condition === 'video_tutorial' ? 'VIDEO' : 'AI';
                      $score = (float)($log['final_objective_success_score'] ?? 0);
                      $review = $reviewsByLogId[$logId] ?? null;
                      $reviewed = $review !== null;
                      $hasFailures = (int)$log['visible_test_passed'] !== 1 || (int)$log['hidden_test_1_passed'] !== 1 || (int)$log['hidden_test_2_passed'] !== 1 || (int)$log['decreasing_property_passed'] !== 1;
                      $needsAttention = !$reviewed && ($hasFailures || (int)$log['hardcoded_solution_detected'] === 1);
                      $reviewState = $reviewed ? 'reviewed' : 'not_reviewed';
                      $reviewLabel = $reviewed ? 'Reviewed' : ($needsAttention ? 'Needs attention' : 'Not reviewed');
                      $reviewClass = $reviewed ? 'reviewed' : ($needsAttention ? 'needs-attention' : 'not-reviewed');
                      $failureReason = objectiveFailureReason($log);
                      $detailId = 'objective-details-' . $logId;
                      $passedTests = (int)$log['visible_test_passed'] + (int)$log['hidden_test_1_passed'] + (int)$log['hidden_test_2_passed'] + (int)$log['decreasing_property_passed'];
                      $adminGradeAvailable = $reviewed && isset($review['final_grade']);
                      $adminGrade = $adminGradeAvailable ? (float)$review['final_grade'] : null;
                      $combinedFinalGrade = $adminGradeAvailable ? (($score + $adminGrade) / 2) : null;
                      $isHardcoded = (int)$log['hardcoded_solution_detected'] === 1;
                      $isCompleted = (int)$log['completed_successfully'] === 1;

                      if ($passedTests === 4 && !$isHardcoded && $isCompleted) {
                          $overallOutcome = 'Successful';
                          $overallOutcomeClass = 'successful';
                          $overallOutcomeIcon = '✓';
                      } elseif ($passedTests > 0 || $score > 0) {
                          $overallOutcome = 'Partially successful';
                          $overallOutcomeClass = 'partial';
                          $overallOutcomeIcon = '◐';
                      } else {
                          $overallOutcome = 'Failed';
                          $overallOutcomeClass = 'failed';
                          $overallOutcomeIcon = '×';
                      }

                      $ringGrade = $combinedFinalGrade;
                      $scoreForGraphic = $ringGrade !== null ? max(0, min(100, $ringGrade)) : 0;
                      $scoreRingColor = $ringGrade === null
                          ? '#64748b'
                          : ($ringGrade >= 80 ? '#22c55e' : ($ringGrade >= 50 ? '#f59e0b' : '#ef4444'));
                    ?>
                    <tr
                      class="objective-row"
                      data-detail-id="<?= escape($detailId) ?>"
                      data-log-id="<?= $logId ?>"
                      data-participant="<?= escape(strtolower((string)($log['user_id'] ?? ''))) ?>"
                      data-completed="<?= (int)$log['completed_successfully'] === 1 ? 'completed' : 'not_completed' ?>"
                      data-failure="<?= $hasFailures ? 'has_failures' : 'passed_all' ?>"
                      data-hardcoded="<?= (int)$log['hardcoded_solution_detected'] === 1 ? 'hardcoded' : 'not_hardcoded' ?>"
                      data-review="<?= escape($reviewState) ?>"
                      data-score="<?= escape((string)($combinedFinalGrade ?? -1)) ?>"
                      data-created="<?= escape((string)($log['created_at'] ?? '')) ?>"
                    >
                      <td><span class="table-id"><?= escape($log['user_id'] ?? '-') ?></span></td>
                      <td><span class="badge <?= $conditionClass ?>"><?= $groupLabel ?></span></td>
                      <td><span class="badge <?= ((int)$log['visible_test_passed'] === 1) ? 'yes' : 'no' ?>"><?= formatBool($log['visible_test_passed']) ?></span></td>
                      <td><span class="badge <?= ((int)$log['hidden_test_1_passed'] === 1) ? 'yes' : 'no' ?>"><?= formatBool($log['hidden_test_1_passed']) ?></span></td>
                      <td><span class="badge <?= ((int)$log['hidden_test_2_passed'] === 1) ? 'yes' : 'no' ?>"><?= formatBool($log['hidden_test_2_passed']) ?></span></td>
                      <td><span class="badge <?= ((int)$log['decreasing_property_passed'] === 1) ? 'yes' : 'no' ?>"><?= formatBool($log['decreasing_property_passed']) ?></span></td>
                      <td><span class="badge <?= ((int)$log['hardcoded_solution_detected'] === 1) ? 'warning' : 'yes' ?>"><?= formatBool($log['hardcoded_solution_detected']) ?></span></td>
                      <td data-score-cell-log-id="<?= $logId ?>"><?php if ($combinedFinalGrade !== null): ?><span class="<?= scoreClass($combinedFinalGrade) ?>"><?= number_format($combinedFinalGrade, 2) ?></span><?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?></td>
                      <td><span class="badge <?= ((int)$log['completed_successfully'] === 1) ? 'yes' : 'no' ?>"><?= formatBool($log['completed_successfully']) ?></span></td>
                      <td><?= escape($failureReason) ?></td>
                      <td data-review-cell-log-id="<?= $logId ?>">
                        <span class="badge review-status-badge <?= $reviewClass ?>"><?= escape($reviewLabel) ?></span>
                        <?php if ($reviewed && isset($review['final_grade'])): ?>
                          <div style="margin-top:5px;font-size:.68rem;color:var(--text-muted);">Admin grade: <?= number_format((float)$review['final_grade'], 2) ?></div>
                        <?php endif; ?>
                      </td>
                      <td><?= escape($log['created_at'] ?? '-') ?></td>
                    </tr>

                    <tr class="objective-details-row" id="<?= escape($detailId) ?>">
                      <td colspan="12">
                        <div class="objective-details-outer">
                          <div class="objective-details-inner">
                            <div class="objective-details-panel">
                              <div class="objective-outcome-visual">
                                <div class="objective-score-ring-wrap">
                                  <div class="objective-score-ring" style="--score-color: <?= escape($scoreRingColor) ?>;" data-score="<?= number_format($scoreForGraphic, 2, '.', '') ?>" data-ring-log-id="<?= $logId ?>">
                                    <svg class="objective-score-ring-svg" viewBox="0 0 120 120" aria-hidden="true">
                                      <circle class="objective-score-ring-track" cx="60" cy="60" r="54"></circle>
                                      <circle class="objective-score-ring-progress" cx="60" cy="60" r="54"></circle>
                                    </svg>
                                    <div class="objective-score-ring-content">
                                      <div class="objective-score-ring-value"><?= $combinedFinalGrade !== null ? number_format($combinedFinalGrade, 0) : '—' ?></div>
                                      <div class="objective-score-ring-label">Final grade / 100</div>
                                    </div>
                                  </div>
                                </div>

                                <div class="objective-outcome-content">
                                  <div class="overall-outcome-badge <?= escape($overallOutcomeClass) ?>">
                                    <span><?= escape($overallOutcomeIcon) ?></span>
                                    <span><?= escape($overallOutcome) ?></span>
                                  </div>

                                  <div>
                                    <div class="objective-outcome-title">Overall outcome</div>
                                    <div class="objective-outcome-subtitle">
                                      <?= $passedTests ?> από τα 4 κριτήρια ελέγχου πέρασαν<?= $isHardcoded ? ', ενώ εντοπίστηκε hardcoded λύση' : '' ?>.
                                    </div>
                                  </div>

                                </div>


                                <div class="objective-grade-comparison" aria-label="Score comparison">
                                  <div class="objective-grade-card system">
                                    <div class="objective-grade-label">System objective grade</div>
                                    <div class="objective-grade-value <?= scoreClass($score) ?>">
                                      <?= number_format($score, 2) ?><span>/ 100</span>
                                    </div>
                                  </div>

                                  <div class="objective-grade-card admin" data-grade-role="admin" data-log-id="<?= $logId ?>">
                                    <div class="objective-grade-label">Admin grade</div>
                                    <?php if ($adminGradeAvailable): ?>
                                      <div class="objective-grade-value">
                                        <?= number_format($adminGrade, 2) ?><span>/ 100</span>
                                      </div>
                                    <?php else: ?>
                                      <div class="objective-grade-missing">Not graded yet</div>
                                    <?php endif; ?>
                                  </div>

                                  <div class="objective-grade-card final" data-grade-role="combined" data-log-id="<?= $logId ?>">
                                    <div class="objective-grade-label">Final combined grade</div>
                                    <?php if ($combinedFinalGrade !== null): ?>
                                      <div class="objective-grade-value <?= scoreClass($combinedFinalGrade) ?>">
                                        <?= number_format($combinedFinalGrade, 2) ?><span>/ 100</span>
                                      </div>
                                    <?php else: ?>
                                      <div class="objective-grade-missing">Available after admin grading</div>
                                    <?php endif; ?>
                                  </div>
                                </div>
                              </div>

                              <div class="objective-summary-strip">
                                <div class="objective-summary-item"><div class="objective-summary-label">Passed tests</div><div class="objective-summary-value"><?= $passedTests ?> / 4</div></div>
                                <div class="objective-summary-item"><div class="objective-summary-label">Failed tests</div><div class="objective-summary-value"><?= 4 - $passedTests ?> / 4</div></div>
                                <div class="objective-summary-item"><div class="objective-summary-label">Hardcoded</div><div class="objective-summary-value"><?= formatBool($log['hardcoded_solution_detected']) ?></div></div>
                                <div class="objective-summary-item"><div class="objective-summary-label">Automatic Objective Score</div><div class="objective-summary-value <?= scoreClass($score) ?>"><?= number_format($score, 2) ?> / 100</div></div>
                                <div class="objective-summary-item"><div class="objective-summary-label">Completion</div><div class="objective-summary-value"><?= (int)$log['completed_successfully'] === 1 ? 'Completed' : 'Incomplete' ?></div></div>
                              </div>

                              <div class="test-progress" aria-label="Test progress">
                                <div class="test-progress-item <?= (int)$log['visible_test_passed'] === 1 ? 'pass' : '' ?>">
                                  <div class="test-progress-label"><span>Visible</span><span class="test-progress-state"><?= (int)$log['visible_test_passed'] === 1 ? 'Passed' : 'Failed' ?></span></div>
                                  <div class="test-progress-segment" title="Visible test: <?= (int)$log['visible_test_passed'] === 1 ? 'Passed' : 'Failed' ?>"></div>
                                </div>
                                <div class="test-progress-item <?= (int)$log['hidden_test_1_passed'] === 1 ? 'pass' : '' ?>">
                                  <div class="test-progress-label"><span>Hidden 1</span><span class="test-progress-state"><?= (int)$log['hidden_test_1_passed'] === 1 ? 'Passed' : 'Failed' ?></span></div>
                                  <div class="test-progress-segment" title="Hidden test 1: <?= (int)$log['hidden_test_1_passed'] === 1 ? 'Passed' : 'Failed' ?>"></div>
                                </div>
                                <div class="test-progress-item <?= (int)$log['hidden_test_2_passed'] === 1 ? 'pass' : '' ?>">
                                  <div class="test-progress-label"><span>Hidden 2</span><span class="test-progress-state"><?= (int)$log['hidden_test_2_passed'] === 1 ? 'Passed' : 'Failed' ?></span></div>
                                  <div class="test-progress-segment" title="Hidden test 2: <?= (int)$log['hidden_test_2_passed'] === 1 ? 'Passed' : 'Failed' ?>"></div>
                                </div>
                                <div class="test-progress-item <?= (int)$log['decreasing_property_passed'] === 1 ? 'pass' : '' ?>">
                                  <div class="test-progress-label"><span>Decreasing</span><span class="test-progress-state"><?= (int)$log['decreasing_property_passed'] === 1 ? 'Passed' : 'Failed' ?></span></div>
                                  <div class="test-progress-segment" title="Decreasing property: <?= (int)$log['decreasing_property_passed'] === 1 ? 'Passed' : 'Failed' ?>"></div>
                                </div>
                              </div>

                              <div class="objective-details-grid">
                                <div class="objective-detail-section">
                                  <div class="objective-detail-title">Visible Test Output</div>
                                  <div class="objective-detail-meta">Status: <?= (int)$log['visible_test_passed'] === 1 ? 'Passed' : 'Failed' ?></div>
                                  <div class="output-box"><?= escape($log['visible_test_output'] ?? '') ?></div>
                                </div>

                                <div class="objective-detail-section">
                                  <div class="objective-detail-title">Hidden Test 1 Output</div>
                                  <div class="objective-detail-meta">Status: <?= (int)$log['hidden_test_1_passed'] === 1 ? 'Passed' : 'Failed' ?></div>
                                  <div class="output-box"><?= escape($log['hidden_test_1_output'] ?? '') ?></div>
                                </div>

                                <div class="objective-detail-section">
                                  <div class="objective-detail-title">Hidden Test 2 Output</div>
                                  <div class="objective-detail-meta">Status: <?= (int)$log['hidden_test_2_passed'] === 1 ? 'Passed' : 'Failed' ?></div>
                                  <div class="output-box"><?= escape($log['hidden_test_2_output'] ?? '') ?></div>
                                </div>

                                <div class="objective-detail-section">
                                  <div class="objective-detail-title">Decreasing Property</div>
                                  <div class="objective-detail-meta">Status: <?= (int)$log['decreasing_property_passed'] === 1 ? 'Passed' : 'Failed' ?></div>
                                  <div class="output-box"><?= (int)$log['decreasing_property_passed'] === 1 ? 'The decreasing property criterion was satisfied.' : 'The decreasing property criterion was not satisfied.' ?></div>
                                </div>

                                <div class="objective-detail-section full">
                                  <div class="objective-detail-title">Code Snapshot</div>
                                  <button type="button" class="code-box code-snapshot-btn" data-log-id="<?= $logId ?>"><?= escape($log['code_snapshot'] ?? '') ?><span class="code-snapshot-hint">Πάτησε για πλήρη προβολή και αξιολόγηση κώδικα</span></button>
                                </div>

                                <div class="objective-detail-section full ai-evaluator-section" data-ai-evaluation-log-id="<?= $logId ?>">
                                  <div class="ai-evaluator-header">
                                    <div>
                                      <div class="objective-detail-title">AI Evaluator</div>
                                      <div class="objective-detail-meta">
                                        Κρυφή server-side αξιολόγηση του submission. Δεν εμφανίζει διορθώσεις στον συμμετέχοντα.
                                      </div>
                                    </div>
                                    <button
                                      type="button"
                                      class="review-btn ai-evaluator-run-btn"
                                      data-ai-run-log-id="<?= $logId ?>"
                                      onclick="runAiEvaluation(<?= $logId ?>, this)"
                                    >
                                      <?= $aiEvaluation ? 'Επανεκτέλεση AI αξιολόγησης' : 'Εκτέλεση AI αξιολόγησης' ?>
                                    </button>
                                  </div>

                                  <div class="ai-evaluator-status" data-ai-status-log-id="<?= $logId ?>">
                                    <?php if ($aiEvaluation): ?>
                                      Τελευταία ενημέρωση: <?= escape($aiEvaluation['updated_at'] ?? '-') ?>
                                    <?php else: ?>
                                      Δεν έχει πραγματοποιηθεί AI αξιολόγηση.
                                    <?php endif; ?>
                                  </div>

                                  <div class="ai-evaluator-content" data-ai-content-log-id="<?= $logId ?>">
                                    <?php if ($aiEvaluation): ?>
                                      <div class="ai-evaluator-grid">
                                        <div class="ai-evaluator-metric">
                                          <span>AI score</span>
                                          <strong><?= isset($aiEvaluation['ai_evaluation_score']) ? number_format((float)$aiEvaluation['ai_evaluation_score'], 2) : '—' ?> / 100</strong>
                                        </div>
                                        <div class="ai-evaluator-metric">
                                          <span>Verdict</span>
                                          <strong><?= escape(strtoupper((string)($aiEvaluation['verdict'] ?? '—'))) ?></strong>
                                        </div>
                                        <div class="ai-evaluator-metric">
                                          <span>Confidence</span>
                                          <strong><?= isset($aiEvaluation['confidence']) ? number_format((float)$aiEvaluation['confidence'] * 100, 1) . '%' : '—' ?></strong>
                                        </div>
                                        <div class="ai-evaluator-metric">
                                          <span>Complexity</span>
                                          <strong><?= escape($aiEvaluation['complexity_class'] ?? '—') ?></strong>
                                        </div>
                                      </div>

                                      <div class="ai-evaluator-flags">
                                        <span class="badge <?= !empty($aiEvaluation['uses_required_algorithm']) ? 'yes' : 'no' ?>">Required algorithm: <?= !empty($aiEvaluation['uses_required_algorithm']) ? 'YES' : 'NO' ?></span>
                                        <span class="badge <?= !empty($aiEvaluation['general_solution']) ? 'yes' : 'no' ?>">General solution: <?= !empty($aiEvaluation['general_solution']) ? 'YES' : 'NO' ?></span>
                                        <span class="badge <?= !empty($aiEvaluation['hardcoding_detected']) ? 'warning' : 'yes' ?>">Hardcoding: <?= !empty($aiEvaluation['hardcoding_detected']) ? 'YES' : 'NO' ?></span>
                                        <span class="badge ai">Status: <?= escape(strtoupper((string)($aiEvaluation['evaluation_status'] ?? '—'))) ?></span>
                                      </div>

                                      <div class="ai-evaluator-score-breakdown">
                                        <div>Algorithm <strong><?= escape((string)($aiEvaluation['algorithm_score'] ?? '—')) ?> / 40</strong></div>
                                        <div>Generality <strong><?= escape((string)($aiEvaluation['generality_score'] ?? '—')) ?> / 25</strong></div>
                                        <div>Complexity <strong><?= escape((string)($aiEvaluation['complexity_score'] ?? '—')) ?> / 20</strong></div>
                                        <div>Robustness <strong><?= escape((string)($aiEvaluation['robustness_score'] ?? '—')) ?> / 15</strong></div>
                                      </div>

                                      <div class="objective-detail-meta ai-evaluator-meta">
                                        Model: <?= escape($aiEvaluation['ai_model'] ?? '—') ?>
                                        · Evaluator: <?= escape($aiEvaluation['evaluator_version'] ?? '—') ?>
                                        · Cache: <?= !empty($aiEvaluation['was_cached']) ? 'YES' : 'NO' ?>
                                      </div>

                                      <div class="ai-rationale-codes">
                                        <?php
                                          $aiCodes = json_decode((string)($aiEvaluation['rationale_codes'] ?? '[]'), true);
                                          $aiCodes = is_array($aiCodes) ? $aiCodes : [];
                                        ?>
                                        <?php if ($aiCodes): ?>
                                          <?php foreach ($aiCodes as $aiCode): ?>
                                            <span class="mini-chip"><?= escape((string)$aiCode) ?></span>
                                          <?php endforeach; ?>
                                        <?php else: ?>
                                          <span class="objective-detail-meta">Δεν υπάρχουν rationale codes.</span>
                                        <?php endif; ?>
                                      </div>
                                    <?php else: ?>
                                      <div class="empty-state ai-evaluator-empty">
                                        Πάτησε «Εκτέλεση AI αξιολόγησης» για να αξιολογηθεί το συγκεκριμένο submission.
                                      </div>
                                    <?php endif; ?>
                                  </div>
                                </div>
                              </div>
                            </div>
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


  <div class="objective-details-modal-overlay" id="objectiveDetailsModalOverlay" aria-hidden="true">
    <div class="objective-details-modal" role="dialog" aria-modal="true" aria-labelledby="objectiveDetailsModalTitle">
      <div class="objective-details-modal-header">
        <div>
          <h2 class="objective-details-modal-title" id="objectiveDetailsModalTitle">Objective Success Details</h2>
          <div class="objective-details-modal-subtitle" id="objectiveDetailsModalSubtitle">Participant details</div>
        </div>
        <button type="button" class="objective-details-modal-close" id="objectiveDetailsModalClose" aria-label="Κλείσιμο">×</button>
      </div>
      <div class="objective-details-modal-body" id="objectiveDetailsModalBody"></div>
    </div>
  </div>

  <div class="sticky-header-clone" id="objectiveStickyHeader" aria-hidden="true"></div>

  <div class="code-modal-overlay" id="codeModalOverlay" aria-hidden="true">
    <div class="code-modal" role="dialog" aria-modal="true" aria-labelledby="codeModalTitle">
      <div class="code-modal-header">
        <div>
          <h2 class="code-modal-title" id="codeModalTitle">Code Snapshot</h2>
          <div class="code-modal-subtitle" id="codeModalSubtitle">Objective success log</div>
        </div>

        <button type="button" class="code-modal-close" id="codeModalClose" aria-label="Κλείσιμο">
          ×
        </button>
      </div>

      <div class="code-modal-body">
        <div class="code-review-toolbar">
          <div class="code-review-tools">
            <button type="button" class="review-btn correct" id="markCorrectBtn">Σωστό τμήμα</button>
            <button type="button" class="review-btn wrong" id="markWrongBtn">Λάθος τμήμα</button>
            <button type="button" class="review-btn" id="clearMarksBtn">Καθαρισμός σημάνσεων</button>
          </div>

          <div class="code-review-save">
            <input
              type="number"
              min="0"
              max="100"
              step="0.01"
              class="review-grade-input"
              id="finalGradeInput"
              placeholder="Admin grade / 100"
            >
            <button type="button" class="review-btn save" id="saveReviewBtn">Αποθήκευση</button>
          </div>
        </div>

        <div class="review-help">
          Επίλεξε με το ποντίκι το κομμάτι κώδικα που θέλεις και πάτησε
          <strong>Σωστό τμήμα</strong> ή <strong>Λάθος τμήμα</strong>.
        </div>

        <pre class="code-modal-pre" id="codeModalContent" contenteditable="true" spellcheck="false"></pre>

        <textarea class="review-notes" id="reviewNotesInput" placeholder="Προαιρετικές σημειώσεις αξιολόγησης..."></textarea>

        <div class="review-status" id="reviewStatus"></div>
      </div>
    </div>
  </div>

  <script>
    const objectiveLogData = <?= json_encode($modalLogs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    let activeLogId = null;

    let objectiveStickyHeaderInitialized = false;
    let objectiveSortState = { key: '', direction: 'asc' };

    function closeAllObjectiveDetails() {
      document.querySelectorAll('.objective-details-row').forEach(function (row) {
        row.classList.remove('open');
      });
      document.querySelectorAll('.objective-row').forEach(function (row) {
        row.classList.remove('active-row');
      });
    }

    function openObjectiveDetailsModal(row) {
      const detailId = row.dataset.detailId;
      const details = document.getElementById(detailId);
      const overlay = document.getElementById('objectiveDetailsModalOverlay');
      const body = document.getElementById('objectiveDetailsModalBody');
      const subtitle = document.getElementById('objectiveDetailsModalSubtitle');
      if (!details || !overlay || !body) return;

      const panel = details.querySelector('.objective-details-panel');
      if (!panel) return;

      closeAllObjectiveDetails();
      row.classList.add('active-row');
      body.innerHTML = panel.outerHTML;

      const animatedPanel = body.querySelector('.objective-details-panel');
      if (animatedPanel) {
        animatedPanel.classList.remove('results-animate-in');
        window.requestAnimationFrame(function () {
          window.requestAnimationFrame(function () {
            animatedPanel.classList.add('results-animate-in');
            const scoreRing = animatedPanel.querySelector('.objective-score-ring');
            const scoreProgress = animatedPanel.querySelector('.objective-score-ring-progress');
            if (scoreRing && scoreProgress) {
              const circumference = 339.292;
              const scoreValue = Math.max(0, Math.min(100, Number(scoreRing.dataset.score || 0)));
              scoreProgress.style.strokeDashoffset = String(circumference);
              window.requestAnimationFrame(function () {
                scoreProgress.style.strokeDashoffset = String(circumference * (1 - scoreValue / 100));
              });
            }
          });
        });
      }

      if (subtitle) {
        const participant = row.querySelector('td:first-child')?.textContent?.trim() || '-';
        const score = row.dataset.score || '-';
        subtitle.textContent = 'Participant: ' + participant + ' | Automatic score: ' + score;
      }

      document.body.classList.add('modal-open');
      overlay.classList.add('active');
      overlay.setAttribute('aria-hidden', 'false');
      if (window.updateObjectiveStickyHeader) window.updateObjectiveStickyHeader();
    }

    function closeObjectiveDetailsModal() {
      const overlay = document.getElementById('objectiveDetailsModalOverlay');
      const body = document.getElementById('objectiveDetailsModalBody');
      if (!overlay) return;

      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      if (body) body.innerHTML = '';
      closeAllObjectiveDetails();

      const codeOverlay = document.getElementById('codeModalOverlay');
      if (!codeOverlay || !codeOverlay.classList.contains('active')) {
        document.body.classList.remove('modal-open');
      }
      if (window.updateObjectiveStickyHeader) window.updateObjectiveStickyHeader();
    }

    function applyObjectiveFilters() {
      const search = (document.getElementById('objectiveSearch')?.value || '').trim().toLowerCase();
      const completion = document.getElementById('completionFilter')?.value || 'all';
      const failure = document.getElementById('failureFilter')?.value || 'all';
      const hardcoded = document.getElementById('hardcodedFilter')?.value || 'all';
      const review = document.getElementById('reviewFilter')?.value || 'all';
      const rows = Array.from(document.querySelectorAll('.objective-row'));
      let visible = 0;

      rows.forEach(function (row) {
        const matches =
          (!search || (row.dataset.participant || '').includes(search)) &&
          (completion === 'all' || row.dataset.completed === completion) &&
          (failure === 'all' || row.dataset.failure === failure) &&
          (hardcoded === 'all' || row.dataset.hardcoded === hardcoded) &&
          (review === 'all' || row.dataset.review === review);

        row.style.display = matches ? '' : 'none';
        const detail = document.getElementById(row.dataset.detailId);
        if (detail) {
          detail.style.display = matches ? '' : 'none';
          if (!matches) detail.classList.remove('open');
        }

        if (matches) visible++;
      });

      const count = document.getElementById('objectiveVisibleCount');
      if (count) count.textContent = 'Εμφανίζονται ' + visible + ' από ' + rows.length + ' logs';

      if (window.updateObjectiveStickyHeader) window.updateObjectiveStickyHeader();
    }

    function sortObjectiveRows(key) {
      const tbody = document.getElementById('objectiveTableBody');
      if (!tbody) return;

      if (objectiveSortState.key === key) {
        objectiveSortState.direction = objectiveSortState.direction === 'asc' ? 'desc' : 'asc';
      } else {
        objectiveSortState.key = key;
        objectiveSortState.direction = 'asc';
      }

      const rows = Array.from(tbody.querySelectorAll('.objective-row'));
      rows.sort(function (a, b) {
        let av;
        let bv;

        if (key === 'score') {
          av = Number(a.dataset.score || 0);
          bv = Number(b.dataset.score || 0);
        } else if (key === 'completed') {
          av = a.dataset.completed === 'completed' ? 1 : 0;
          bv = b.dataset.completed === 'completed' ? 1 : 0;
        } else if (key === 'created') {
          av = Date.parse(a.dataset.created || '') || 0;
          bv = Date.parse(b.dataset.created || '') || 0;
        } else {
          av = (a.dataset.participant || '').toLowerCase();
          bv = (b.dataset.participant || '').toLowerCase();
        }

        if (av < bv) return objectiveSortState.direction === 'asc' ? -1 : 1;
        if (av > bv) return objectiveSortState.direction === 'asc' ? 1 : -1;
        return 0;
      });

      rows.forEach(function (row) {
        const detail = document.getElementById(row.dataset.detailId);
        tbody.appendChild(row);
        if (detail) tbody.appendChild(detail);
      });

      if (window.updateObjectiveStickyHeader) window.updateObjectiveStickyHeader();
    }

    function initObjectiveStickyHeader() {
      if (objectiveStickyHeaderInitialized) return;

      const table = document.getElementById('objectiveSuccessTable');
      const wrap = document.getElementById('objectiveTableWrap');
      const sticky = document.getElementById('objectiveStickyHeader');

      if (!table || !wrap || !sticky || !table.tHead) return;

      const cloneTable = table.cloneNode(false);
      cloneTable.removeAttribute('id');
      cloneTable.className = table.className + ' sticky-clone-table';
      cloneTable.appendChild(table.tHead.cloneNode(true));

      sticky.innerHTML = '';
      sticky.appendChild(cloneTable);

      function syncColumnWidths() {
        const originalHeaders = table.tHead ? table.tHead.querySelectorAll('th') : [];
        const clonedHeaders = sticky.querySelectorAll('th');

        originalHeaders.forEach(function (th, index) {
          if (!clonedHeaders[index]) return;
          const width = th.getBoundingClientRect().width;
          clonedHeaders[index].style.width = width + 'px';
          clonedHeaders[index].style.minWidth = width + 'px';
          clonedHeaders[index].style.maxWidth = width + 'px';
        });

        cloneTable.style.width = table.getBoundingClientRect().width + 'px';
        cloneTable.style.minWidth = table.getBoundingClientRect().width + 'px';
      }

      function updateStickyHeader() {
        if (document.body.classList.contains('modal-open')) {
          sticky.classList.remove('visible');
          return;
        }

        const topbar = document.querySelector('.topbar');
        const topbarBottom = topbar ? topbar.getBoundingClientRect().bottom : 0;
        const wrapRect = wrap.getBoundingClientRect();
        const tableRect = table.getBoundingClientRect();
        const theadRect = table.tHead.getBoundingClientRect();

        const shouldShow =
          theadRect.top <= topbarBottom &&
          wrapRect.bottom > topbarBottom + theadRect.height &&
          wrapRect.top < topbarBottom;

        if (!shouldShow) {
          sticky.classList.remove('visible');
          return;
        }

        syncColumnWidths();

        sticky.style.top = topbarBottom + 'px';
        sticky.style.left = wrapRect.left + 'px';
        sticky.style.width = wrapRect.width + 'px';
        sticky.style.height = theadRect.height + 'px';

        cloneTable.style.transform = 'translateX(' + (tableRect.left - wrapRect.left) + 'px)';
        sticky.classList.add('visible');
      }

      window.updateObjectiveStickyHeader = updateStickyHeader;

      window.addEventListener('scroll', updateStickyHeader, { passive: true });
      window.addEventListener('resize', updateStickyHeader);
      wrap.addEventListener('scroll', updateStickyHeader, { passive: true });

      objectiveStickyHeaderInitialized = true;
      updateStickyHeader();
    }


    function toggleSidebar() {
      const sidebar = document.getElementById('sidebar');
      if (sidebar) {
        sidebar.classList.toggle('is-open');
      }
    }

    function setReviewStatus(message, type) {
      const status = document.getElementById('reviewStatus');

      if (!status) return;

      status.textContent = message || '';
      status.classList.remove('ok', 'error');

      if (type) {
        status.classList.add(type);
      }
    }

    function escapeHtml(value) {
      return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function openCodeSnapshotModal(logId) {
      const overlay = document.getElementById('codeModalOverlay');
      const subtitle = document.getElementById('codeModalSubtitle');
      const content = document.getElementById('codeModalContent');
      const gradeInput = document.getElementById('finalGradeInput');
      const notesInput = document.getElementById('reviewNotesInput');

      if (!overlay || !subtitle || !content || !gradeInput || !notesInput) return;

      const log = objectiveLogData[String(logId)] || null;

      activeLogId = logId;

      if (!log) {
        subtitle.textContent = 'Objective success log ID: ' + logId;
        content.textContent = 'Δεν βρέθηκαν δεδομένα για αυτό το log.';
        gradeInput.value = '';
        notesInput.value = '';
      } else {
        subtitle.textContent = 'Log ID: ' + log.log_id + ' | User: ' + log.user_id + ' | Task: ' + log.task_id;

        if (log.marked_code_html && log.marked_code_html.trim() !== '') {
          content.innerHTML = log.marked_code_html;
        } else {
          content.textContent = log.code_snapshot && log.code_snapshot.trim() !== ''
            ? log.code_snapshot
            : 'Δεν υπάρχει διαθέσιμο code snapshot για αυτό το log.';
        }

        gradeInput.value = log.final_grade || '';
        notesInput.value = log.review_notes || '';

        if (log.review_updated_at) {
          setReviewStatus('Τελευταία αποθήκευση: ' + log.review_updated_at, '');
        } else {
          setReviewStatus('', '');
        }
      }

      document.body.classList.add('modal-open');
      if (window.updateObjectiveStickyHeader) {
        window.updateObjectiveStickyHeader();
      }
      overlay.classList.add('active');
      overlay.setAttribute('aria-hidden', 'false');

      setTimeout(function () {
        content.focus();
      }, 80);
    }

    function closeCodeSnapshotModal() {
      const overlay = document.getElementById('codeModalOverlay');

      if (!overlay) return;

      activeLogId = null;
      overlay.classList.remove('active');
      const detailsOverlay = document.getElementById('objectiveDetailsModalOverlay');
      if (!detailsOverlay || !detailsOverlay.classList.contains('active')) {
        document.body.classList.remove('modal-open');
      }
      if (window.updateObjectiveStickyHeader) {
        window.updateObjectiveStickyHeader();
      }
      overlay.setAttribute('aria-hidden', 'true');
    }

    function selectionIsInsideCodeModal() {
      const content = document.getElementById('codeModalContent');
      const selection = window.getSelection();

      if (!content || !selection || selection.rangeCount === 0) {
        return false;
      }

      const range = selection.getRangeAt(0);

      return content.contains(range.commonAncestorContainer);
    }

    function markSelection(type) {
      const color = type === 'correct'
        ? 'rgba(34, 197, 94, 0.35)'
        : 'rgba(239, 68, 68, 0.35)';

      if (!selectionIsInsideCodeModal()) {
        setReviewStatus('Πρώτα επίλεξε ένα τμήμα κώδικα μέσα στο modal.', 'error');
        return;
      }

      const selection = window.getSelection();

      if (!selection || selection.toString().trim() === '') {
        setReviewStatus('Πρώτα επίλεξε ένα τμήμα κώδικα μέσα στο modal.', 'error');
        return;
      }

      document.execCommand('backColor', false, color);
      setReviewStatus(type === 'correct' ? 'Το τμήμα σημειώθηκε ως σωστό.' : 'Το τμήμα σημειώθηκε ως λάθος.', 'ok');
    }

    function clearMarks() {
      const content = document.getElementById('codeModalContent');

      if (!content || !activeLogId) return;

      const log = objectiveLogData[String(activeLogId)] || null;
      content.textContent = log && log.code_snapshot ? log.code_snapshot : '';

      setReviewStatus('Οι σημάνσεις καθαρίστηκαν. Πάτησε αποθήκευση για να κρατηθεί η αλλαγή.', '');
    }


    function escapeAiHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function aiBoolBadge(label, value, warningWhenTrue) {
      const isTrue = value === true || value === 1 || value === '1';
      const cssClass = warningWhenTrue
        ? (isTrue ? 'warning' : 'yes')
        : (isTrue ? 'yes' : 'no');

      return '<span class="badge ' + cssClass + '">' +
        escapeAiHtml(label) + ': ' + (isTrue ? 'YES' : 'NO') +
        '</span>';
    }

    function renderAiEvaluationHtml(evaluation) {
      if (!evaluation) {
        return '<div class="empty-state ai-evaluator-empty">Δεν υπάρχει διαθέσιμη AI αξιολόγηση.</div>';
      }

      const score = Number(evaluation.ai_evaluation_score ?? 0);
      const confidence = Number(evaluation.confidence ?? 0) * 100;
      const codes = Array.isArray(evaluation.rationale_codes)
        ? evaluation.rationale_codes
        : [];

      const codeHtml = codes.length
        ? codes.map(function (code) {
            return '<span class="mini-chip">' + escapeAiHtml(code) + '</span>';
          }).join('')
        : '<span class="objective-detail-meta">Δεν υπάρχουν rationale codes.</span>';

      return '' +
        '<div class="ai-evaluator-grid">' +
          '<div class="ai-evaluator-metric"><span>AI score</span><strong>' + score.toFixed(2) + ' / 100</strong></div>' +
          '<div class="ai-evaluator-metric"><span>Verdict</span><strong>' + escapeAiHtml(String(evaluation.verdict || '—').toUpperCase()) + '</strong></div>' +
          '<div class="ai-evaluator-metric"><span>Confidence</span><strong>' + confidence.toFixed(1) + '%</strong></div>' +
          '<div class="ai-evaluator-metric"><span>Complexity</span><strong>' + escapeAiHtml(evaluation.complexity_class || '—') + '</strong></div>' +
        '</div>' +
        '<div class="ai-evaluator-flags">' +
          aiBoolBadge('Required algorithm', evaluation.uses_required_algorithm, false) +
          aiBoolBadge('General solution', evaluation.general_solution, false) +
          aiBoolBadge('Hardcoding', evaluation.hardcoding_detected, true) +
          '<span class="badge ai">Status: ' + escapeAiHtml(String(evaluation.evaluation_status || '—').toUpperCase()) + '</span>' +
        '</div>' +
        '<div class="ai-evaluator-score-breakdown">' +
          '<div>Algorithm <strong>' + escapeAiHtml(evaluation.algorithm_score ?? '—') + ' / 40</strong></div>' +
          '<div>Generality <strong>' + escapeAiHtml(evaluation.generality_score ?? '—') + ' / 25</strong></div>' +
          '<div>Complexity <strong>' + escapeAiHtml(evaluation.complexity_score ?? '—') + ' / 20</strong></div>' +
          '<div>Robustness <strong>' + escapeAiHtml(evaluation.robustness_score ?? '—') + ' / 15</strong></div>' +
        '</div>' +
        '<div class="objective-detail-meta ai-evaluator-meta">' +
          'Model: ' + escapeAiHtml(evaluation.ai_model || '—') +
          ' · Evaluator: ' + escapeAiHtml(evaluation.evaluator_version || '—') +
          ' · Cache: ' + (evaluation.was_cached ? 'YES' : 'NO') +
        '</div>' +
        '<div class="ai-rationale-codes">' + codeHtml + '</div>';
    }

    function updateAiEvaluationPresentation(logId, evaluation, message, statusType) {
      document.querySelectorAll('[data-ai-content-log-id="' + logId + '"]').forEach(function (container) {
        container.innerHTML = renderAiEvaluationHtml(evaluation);
      });

      document.querySelectorAll('[data-ai-status-log-id="' + logId + '"]').forEach(function (status) {
        status.textContent = message || '';
        status.className = 'ai-evaluator-status' + (statusType ? ' ' + statusType : '');
      });

      document.querySelectorAll('[data-ai-run-log-id="' + logId + '"]').forEach(function (button) {
        button.textContent = 'Επανεκτέλεση AI αξιολόγησης';
      });
    }

    async function runAiEvaluation(objectiveLogId, triggerButton) {
      const buttons = Array.from(
        document.querySelectorAll('[data-ai-run-log-id="' + objectiveLogId + '"]')
      );

      buttons.forEach(function (button) {
        button.disabled = true;
        button.textContent = 'Εκτελείται AI αξιολόγηση...';
      });

      document.querySelectorAll('[data-ai-status-log-id="' + objectiveLogId + '"]').forEach(function (status) {
        status.textContent = 'Η AI αξιολόγηση εκτελείται στο παρασκήνιο...';
        status.className = 'ai-evaluator-status';
      });

      const formData = new FormData();
      formData.append('action', 'run_ai_evaluation');
      formData.append('objective_log_id', objectiveLogId);

      try {
        const response = await fetch(window.location.href, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.ok) {
          throw new Error([data.message || 'Αποτυχία AI evaluation.', data.details ? 'Λεπτομέρειες: ' + data.details : ''].filter(Boolean).join('\n'));
        }

        const evaluation = data.evaluation || null;

        if (objectiveLogData[String(objectiveLogId)]) {
          objectiveLogData[String(objectiveLogId)].ai_evaluation = evaluation;
        }

        updateAiEvaluationPresentation(
          objectiveLogId,
          evaluation,
          (data.message || 'Η AI αξιολόγηση ολοκληρώθηκε.') +
            (evaluation?.updated_at ? ' Τελευταία ενημέρωση: ' + evaluation.updated_at : ''),
          'ok'
        );
      } catch (error) {
        document.querySelectorAll('[data-ai-status-log-id="' + objectiveLogId + '"]').forEach(function (status) {
          status.textContent = error.message || 'Προέκυψε σφάλμα κατά την AI αξιολόγηση.';
          status.className = 'ai-evaluator-status error';
        });
      } finally {
        buttons.forEach(function (button) {
          button.disabled = false;
          if (button.textContent === 'Εκτελείται AI αξιολόγηση...') {
            button.textContent = 'Εκτέλεση AI αξιολόγησης';
          }
        });
      }
    }

    async function saveReview() {
      const content = document.getElementById('codeModalContent');
      const gradeInput = document.getElementById('finalGradeInput');
      const notesInput = document.getElementById('reviewNotesInput');
      const saveBtn = document.getElementById('saveReviewBtn');

      if (!content || !gradeInput || !notesInput || !activeLogId) return;

      const grade = gradeInput.value.trim();

      if (grade === '' || Number.isNaN(Number(grade))) {
        setReviewStatus('Συμπλήρωσε έγκυρο βαθμό admin.', 'error');
        return;
      }

      if (Number(grade) < 0 || Number(grade) > 100) {
        setReviewStatus('Ο βαθμός admin πρέπει να είναι από 0 έως 100.', 'error');
        return;
      }

      const formData = new FormData();
      formData.append('action', 'save_code_review');
      formData.append('objective_log_id', activeLogId);
      formData.append('marked_code_html', content.innerHTML);
      formData.append('final_grade', grade);
      formData.append('review_notes', notesInput.value);

      if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.textContent = 'Αποθήκευση...';
      }

      setReviewStatus('Γίνεται αποθήκευση...', '');

      try {
        const response = await fetch(window.location.href, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.ok) {
          setReviewStatus(data.message || 'Δεν αποθηκεύτηκε η αξιολόγηση.', 'error');
          return;
        }

        if (objectiveLogData[String(activeLogId)]) {
          objectiveLogData[String(activeLogId)].marked_code_html = content.innerHTML;
          objectiveLogData[String(activeLogId)].final_grade = data.final_grade || grade;
          objectiveLogData[String(activeLogId)].combined_grade = data.combined_grade || '';
          objectiveLogData[String(activeLogId)].review_notes = notesInput.value;
          objectiveLogData[String(activeLogId)].review_updated_at = 'μόλις τώρα';
        }

        const adminValue = Number(data.final_grade || grade);
        const combinedValue = Number(data.combined_grade || 0);
        const scoreClassName = combinedValue >= 80 ? 'score-high' : (combinedValue >= 50 ? 'score-mid' : 'score-low');
        const ringColor = combinedValue >= 80 ? '#22c55e' : (combinedValue >= 50 ? '#f59e0b' : '#ef4444');

        function updateGradePresentation(container) {
          if (!container) return;

          const adminCard = container.querySelector('[data-grade-role="admin"][data-log-id="' + activeLogId + '"]');
          const combinedCard = container.querySelector('[data-grade-role="combined"][data-log-id="' + activeLogId + '"]');
          const scoreRing = container.querySelector('[data-ring-log-id="' + activeLogId + '"]');

          if (adminCard) {
            adminCard.innerHTML = '<div class="objective-grade-label">Admin grade</div>' +
              '<div class="objective-grade-value">' + adminValue.toFixed(2) + '<span>/ 100</span></div>';
          }

          if (combinedCard) {
            combinedCard.innerHTML = '<div class="objective-grade-label">Final combined grade</div>' +
              '<div class="objective-grade-value ' + scoreClassName + '">' + combinedValue.toFixed(2) + '<span>/ 100</span></div>';
          }

          if (scoreRing) {
            scoreRing.dataset.score = combinedValue.toFixed(2);
            scoreRing.style.setProperty('--score-color', ringColor);
            const ringValue = scoreRing.querySelector('.objective-score-ring-value');
            const ringLabel = scoreRing.querySelector('.objective-score-ring-label');
            const ringProgress = scoreRing.querySelector('.objective-score-ring-progress');
            if (ringValue) ringValue.textContent = String(Math.round(combinedValue));
            if (ringLabel) ringLabel.textContent = 'Final grade / 100';
            if (ringProgress) {
              const circumference = 339.292;
              ringProgress.style.strokeDashoffset = String(circumference);
              window.requestAnimationFrame(function () {
                ringProgress.style.strokeDashoffset = String(circumference * (1 - Math.max(0, Math.min(100, combinedValue)) / 100));
              });
            }
          }
        }

        updateGradePresentation(document.getElementById('objectiveDetailsModalBody'));
        updateGradePresentation(document.getElementById('objective-details-' + activeLogId));

        const reviewCell = document.querySelector('[data-review-cell-log-id="' + activeLogId + '"]');
        const scoreCell = document.querySelector('[data-score-cell-log-id="' + activeLogId + '"]');
        const objectiveRow = document.querySelector('.objective-row[data-log-id="' + activeLogId + '"]');
        if (reviewCell) {
          reviewCell.innerHTML = '<span class="badge review-status-badge reviewed">Reviewed</span>' +
            '<div style="margin-top:5px;font-size:.68rem;color:var(--text-muted);">Admin grade: ' + adminValue.toFixed(2) + '</div>';
        }
        if (scoreCell) {
          scoreCell.innerHTML = '<span class="' + scoreClassName + '">' + combinedValue.toFixed(2) + '</span>';
        }
        if (objectiveRow) {
          objectiveRow.dataset.review = 'reviewed';
          objectiveRow.dataset.score = combinedValue.toFixed(2);
        }

        setReviewStatus((data.message || 'Η αξιολόγηση αποθηκεύτηκε.') + ' Τελικός συνδυασμένος βαθμός: ' + Number(data.combined_grade || 0).toFixed(2) + ' / 100.', 'ok');
      } catch (error) {
        setReviewStatus('Προέκυψε σφάλμα κατά την αποθήκευση.', 'error');
      } finally {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Αποθήκευση';
        }
      }
    }

    initObjectiveStickyHeader();

    document.querySelectorAll('.objective-row').forEach(function (row) {
      row.addEventListener('click', function (event) {
        if (event.target.closest('button, a, input, select, textarea')) return;
        openObjectiveDetailsModal(row);
      });
    });

    document.addEventListener('click', function (event) {
      const button = event.target.closest('.code-snapshot-btn');
      if (!button) return;
      event.stopPropagation();
      const logId = button.dataset.logId || '-';
      openCodeSnapshotModal(logId);
    });

    ['objectiveSearch', 'completionFilter', 'failureFilter', 'hardcodedFilter', 'reviewFilter'].forEach(function (id) {
      const element = document.getElementById(id);
      if (!element) return;
      element.addEventListener(id === 'objectiveSearch' ? 'input' : 'change', applyObjectiveFilters);
    });

    document.querySelectorAll('.sortable-header').forEach(function (header) {
      header.addEventListener('click', function () {
        sortObjectiveRows(header.dataset.sortKey || 'participant');
      });
    });

    applyObjectiveFilters();

    const objectiveDetailsModalClose = document.getElementById('objectiveDetailsModalClose');
    const objectiveDetailsModalOverlay = document.getElementById('objectiveDetailsModalOverlay');
    const codeModalClose = document.getElementById('codeModalClose');
    const codeModalOverlay = document.getElementById('codeModalOverlay');
    const markCorrectBtn = document.getElementById('markCorrectBtn');
    const markWrongBtn = document.getElementById('markWrongBtn');
    const clearMarksBtn = document.getElementById('clearMarksBtn');
    const saveReviewBtn = document.getElementById('saveReviewBtn');

    if (objectiveDetailsModalClose) {
      objectiveDetailsModalClose.addEventListener('click', closeObjectiveDetailsModal);
    }

    if (objectiveDetailsModalOverlay) {
      objectiveDetailsModalOverlay.addEventListener('click', function (e) {
        if (e.target === objectiveDetailsModalOverlay) {
          closeObjectiveDetailsModal();
        }
      });
    }

    if (codeModalClose) {
      codeModalClose.addEventListener('click', closeCodeSnapshotModal);
    }

    if (codeModalOverlay) {
      codeModalOverlay.addEventListener('click', function (e) {
        if (e.target === codeModalOverlay) {
          closeCodeSnapshotModal();
        }
      });
    }

    if (markCorrectBtn) {
      markCorrectBtn.addEventListener('click', function () {
        markSelection('correct');
      });
    }

    if (markWrongBtn) {
      markWrongBtn.addEventListener('click', function () {
        markSelection('wrong');
      });
    }

    if (clearMarksBtn) {
      clearMarksBtn.addEventListener('click', clearMarks);
    }

    if (saveReviewBtn) {
      saveReviewBtn.addEventListener('click', saveReview);
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

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        const codeOverlay = document.getElementById('codeModalOverlay');
        if (codeOverlay && codeOverlay.classList.contains('active')) {
          closeCodeSnapshotModal();
          return;
        }
        closeObjectiveDetailsModal();
      }
    });
  </script>
</body>
</html>
