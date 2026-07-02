<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/ai_submission_evaluator.php';

const DB_HOST = 'localhost';
const DB_NAME = 'diploma';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';
const DEFAULT_TASK_ID = 'decreasing_monotonic_stack_v1';
const AUTO_CREATE_TABLES = true;

function diplomaLoggerHandleRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = diplomaGetPdo();
        if (AUTO_CREATE_TABLES) {
            diplomaCreateTables($pdo);
        }

        $payload = diplomaReadPayload();
        $action = (string)($payload['action'] ?? $_POST['action'] ?? '');

        switch ($action) {
            case 'save_objective_log':
            case 'save_submission':
                diplomaSaveObjectiveLog($pdo, $payload);
                break;

            case 'get_objective_log':
                diplomaGetObjectiveLog($pdo, $payload);
                break;

            case 'get_objective_summary':
                diplomaGetObjectiveSummary($pdo, $payload);
                break;

            default:
                diplomaJsonResponse(false, 'Unknown or missing action.');
        }
    } catch (Throwable $e) {
        http_response_code(500);
        diplomaJsonResponse(false, 'Server error: ' . $e->getMessage());
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    diplomaLoggerHandleRequest();
}

function diplomaGetPdo(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function diplomaCreateTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS objective_success_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id VARCHAR(100) NOT NULL,
            `condition` ENUM('ai_assistance', 'video_tutorial') NOT NULL,
            task_id VARCHAR(150) NOT NULL DEFAULT 'decreasing_monotonic_stack_v1',
            code_snapshot LONGTEXT NOT NULL,
            visible_test_passed TINYINT(1) NOT NULL DEFAULT 0,
            visible_test_output TEXT NULL,
            hidden_test_1_passed TINYINT(1) NOT NULL DEFAULT 0,
            hidden_test_1_output TEXT NULL,
            hidden_test_2_passed TINYINT(1) NOT NULL DEFAULT 0,
            hidden_test_2_output TEXT NULL,
            decreasing_property_passed TINYINT(1) NOT NULL DEFAULT 0,
            hardcoded_solution_detected TINYINT(1) NOT NULL DEFAULT 0,
            final_objective_success_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            completed_successfully TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_user_id (user_id),
            INDEX idx_condition (`condition`),
            INDEX idx_task_id (task_id),
            INDEX idx_score (final_objective_success_score),
            INDEX idx_completed_successfully (completed_successfully)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Ακριβώς ο υπάρχων πίνακας που έδωσε ο χρήστης.
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
                FOREIGN KEY (objective_log_id)
                REFERENCES objective_success_logs(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function diplomaReadPayload(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }

    return $_POST;
}

function diplomaParticipantId(array $payload): string
{
    $sessionId = trim((string)($_SESSION['participant_id'] ?? ''));
    if ($sessionId !== '') {
        return $sessionId;
    }

    return trim((string)($payload['participant_id'] ?? $payload['user_id'] ?? ''));
}

function diplomaBoolInt(mixed $value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function diplomaSaveObjectiveLog(PDO $pdo, array $payload): void
{
    $participantId = diplomaParticipantId($payload);
    $condition = trim((string)($payload['condition'] ?? ''));
    $taskId = trim((string)($payload['task_id'] ?? DEFAULT_TASK_ID));
    $code = (string)($payload['code_snapshot'] ?? '');

    if ($participantId === '') {
        diplomaJsonResponse(false, 'Missing participant_id.');
    }
    if (!in_array($condition, ['ai_assistance', 'video_tutorial'], true)) {
        diplomaJsonResponse(false, 'Invalid condition.');
    }
    if (trim($code) === '') {
        diplomaJsonResponse(false, 'Missing code_snapshot.');
    }

    $tests = [
        'visible_test_passed' => diplomaBoolInt($payload['visible_test_passed'] ?? false),
        'visible_test_output' => isset($payload['visible_test_output']) ? (string)$payload['visible_test_output'] : null,
        'hidden_test_1_passed' => diplomaBoolInt($payload['hidden_test_1_passed'] ?? false),
        'hidden_test_1_output' => isset($payload['hidden_test_1_output']) ? (string)$payload['hidden_test_1_output'] : null,
        'hidden_test_2_passed' => diplomaBoolInt($payload['hidden_test_2_passed'] ?? false),
        'hidden_test_2_output' => isset($payload['hidden_test_2_output']) ? (string)$payload['hidden_test_2_output'] : null,
        'decreasing_property_passed' => diplomaBoolInt($payload['decreasing_property_passed'] ?? false),
    ];

    $deterministicScore =
        ($tests['visible_test_passed'] * 20) +
        ($tests['hidden_test_1_passed'] * 20) +
        ($tests['hidden_test_2_passed'] * 20) +
        ($tests['decreasing_property_passed'] * 20);

    $ruleHardcoded =
        $tests['visible_test_passed'] === 1 &&
        $tests['hidden_test_1_passed'] === 0 &&
        $tests['hidden_test_2_passed'] === 0;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO objective_success_logs (
                user_id, `condition`, task_id, code_snapshot,
                visible_test_passed, visible_test_output,
                hidden_test_1_passed, hidden_test_1_output,
                hidden_test_2_passed, hidden_test_2_output,
                decreasing_property_passed, hardcoded_solution_detected,
                final_objective_success_score, completed_successfully
            ) VALUES (
                :user_id, :condition, :task_id, :code_snapshot,
                :visible_test_passed, :visible_test_output,
                :hidden_test_1_passed, :hidden_test_1_output,
                :hidden_test_2_passed, :hidden_test_2_output,
                :decreasing_property_passed, :hardcoded_solution_detected,
                :final_score, 0
            )
        ");
        $stmt->execute([
            ':user_id' => $participantId,
            ':condition' => $condition,
            ':task_id' => $taskId !== '' ? $taskId : DEFAULT_TASK_ID,
            ':code_snapshot' => $code,
            ':visible_test_passed' => $tests['visible_test_passed'],
            ':visible_test_output' => $tests['visible_test_output'],
            ':hidden_test_1_passed' => $tests['hidden_test_1_passed'],
            ':hidden_test_1_output' => $tests['hidden_test_1_output'],
            ':hidden_test_2_passed' => $tests['hidden_test_2_passed'],
            ':hidden_test_2_output' => $tests['hidden_test_2_output'],
            ':decreasing_property_passed' => $tests['decreasing_property_passed'],
            ':hardcoded_solution_detected' => $ruleHardcoded ? 1 : 0,
            ':final_score' => $deterministicScore,
        ]);
        $objectiveLogId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $evaluation = null;
    $evaluationError = null;
    $evaluationStatus = 'failed';

    try {
        $evaluation = evaluateSubmissionWithAi($pdo, $code, $tests);
        $evaluationStatus = ((float)($evaluation['confidence'] ?? 0) < 0.70)
            ? 'manual_review'
            : 'completed';
    } catch (Throwable $e) {
        $evaluationError = $e->getMessage();
        error_log('[AI EVALUATOR] ' . $evaluationError);
    }

    $aiScore = $evaluation !== null ? (float)($evaluation['total_score'] ?? 0) : 0.0;
    $finalScore = min(100.0, round($deterministicScore + ($aiScore * 0.20), 2));
    $hardcoded = $ruleHardcoded || !empty($evaluation['hardcoding_detected']);

    $allTestsPassed =
        $tests['visible_test_passed'] === 1 &&
        $tests['hidden_test_1_passed'] === 1 &&
        $tests['hidden_test_2_passed'] === 1 &&
        $tests['decreasing_property_passed'] === 1;

    $completed =
        $allTestsPassed &&
        $evaluationStatus === 'completed' &&
        ($evaluation['verdict'] ?? '') === 'correct' &&
        !empty($evaluation['uses_required_algorithm']) &&
        !empty($evaluation['general_solution']) &&
        !$hardcoded;

    $evaluationJson = $evaluation !== null
        ? $evaluation
        : [
            'status' => 'error',
            'error' => $evaluationError,
            'evaluator_version' => AI_EVALUATOR_VERSION,
            'model' => AI_EVALUATOR_MODEL,
        ];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ai_submission_evaluations (
                objective_log_id, participant_id, evaluator_version, ai_model,
                evaluation_status, verdict, uses_required_algorithm,
                general_solution, hardcoding_detected,
                algorithm_score, generality_score, complexity_score,
                robustness_score, ai_evaluation_score, confidence,
                complexity_class, rationale_codes, evaluation_json, code_hash
            ) VALUES (
                :objective_log_id, :participant_id, :evaluator_version, :ai_model,
                :evaluation_status, :verdict, :uses_required_algorithm,
                :general_solution, :hardcoding_detected,
                :algorithm_score, :generality_score, :complexity_score,
                :robustness_score, :ai_evaluation_score, :confidence,
                :complexity_class, :rationale_codes, :evaluation_json, :code_hash
            )
            ON DUPLICATE KEY UPDATE
                participant_id = VALUES(participant_id),
                evaluator_version = VALUES(evaluator_version),
                ai_model = VALUES(ai_model),
                evaluation_status = VALUES(evaluation_status),
                verdict = VALUES(verdict),
                uses_required_algorithm = VALUES(uses_required_algorithm),
                general_solution = VALUES(general_solution),
                hardcoding_detected = VALUES(hardcoding_detected),
                algorithm_score = VALUES(algorithm_score),
                generality_score = VALUES(generality_score),
                complexity_score = VALUES(complexity_score),
                robustness_score = VALUES(robustness_score),
                ai_evaluation_score = VALUES(ai_evaluation_score),
                confidence = VALUES(confidence),
                complexity_class = VALUES(complexity_class),
                rationale_codes = VALUES(rationale_codes),
                evaluation_json = VALUES(evaluation_json),
                code_hash = VALUES(code_hash),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':objective_log_id' => $objectiveLogId,
            ':participant_id' => $participantId,
            ':evaluator_version' => $evaluation['evaluator_version'] ?? AI_EVALUATOR_VERSION,
            ':ai_model' => $evaluation['model'] ?? AI_EVALUATOR_MODEL,
            ':evaluation_status' => $evaluationStatus,
            ':verdict' => $evaluation['verdict'] ?? null,
            ':uses_required_algorithm' => isset($evaluation['uses_required_algorithm']) ? (int)$evaluation['uses_required_algorithm'] : null,
            ':general_solution' => isset($evaluation['general_solution']) ? (int)$evaluation['general_solution'] : null,
            ':hardcoding_detected' => isset($evaluation['hardcoding_detected']) ? (int)$evaluation['hardcoding_detected'] : null,
            ':algorithm_score' => $evaluation['algorithm_score'] ?? null,
            ':generality_score' => $evaluation['generality_score'] ?? null,
            ':complexity_score' => $evaluation['complexity_score'] ?? null,
            ':robustness_score' => $evaluation['robustness_score'] ?? null,
            ':ai_evaluation_score' => $evaluation !== null ? $aiScore : null,
            ':confidence' => $evaluation['confidence'] ?? null,
            ':complexity_class' => $evaluation['complexity_class'] ?? null,
            ':rationale_codes' => $evaluation !== null
                ? json_encode($evaluation['rationale_codes'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            ':evaluation_json' => json_encode($evaluationJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':code_hash' => hash('sha256', aiEvaluatorNormalizeCode($code)),
        ]);

        $update = $pdo->prepare("
            UPDATE objective_success_logs
            SET hardcoded_solution_detected = :hardcoded,
                final_objective_success_score = :final_score,
                completed_successfully = :completed
            WHERE id = :id
        ");
        $update->execute([
            ':hardcoded' => $hardcoded ? 1 : 0,
            ':final_score' => $finalScore,
            ':completed' => $completed ? 1 : 0,
            ':id' => $objectiveLogId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    diplomaJsonResponse(true, 'Objective log and AI evaluation saved.', [
        'id' => $objectiveLogId,
        'objective_log_id' => $objectiveLogId,
        'participant_id' => $participantId,
        'condition' => $condition,
        'task_id' => $taskId,
        'deterministic_test_score' => $deterministicScore,
        'ai_evaluation_status' => $evaluationStatus,
        'ai_evaluation_score' => $evaluation !== null ? $aiScore : null,
        'ai_verdict' => $evaluation['verdict'] ?? null,
        'ai_error' => $evaluationError,
        'final_objective_success_score' => $finalScore,
        'hardcoded_solution_detected' => $hardcoded,
        'completed_successfully' => $completed,
    ]);
}

function diplomaGetObjectiveLog(PDO $pdo, array $payload): void
{
    $id = (int)($payload['id'] ?? $payload['objective_log_id'] ?? 0);
    if ($id <= 0) {
        diplomaJsonResponse(false, 'Invalid objective log id.');
    }

    $stmt = $pdo->prepare("
        SELECT osl.*, aie.evaluation_status, aie.evaluator_version, aie.ai_model,
               aie.verdict, aie.uses_required_algorithm, aie.general_solution,
               aie.hardcoding_detected, aie.algorithm_score, aie.generality_score,
               aie.complexity_score, aie.robustness_score, aie.ai_evaluation_score,
               aie.confidence, aie.complexity_class, aie.rationale_codes,
               aie.evaluation_json, aie.updated_at AS ai_updated_at
        FROM objective_success_logs osl
        LEFT JOIN ai_submission_evaluations aie ON aie.objective_log_id = osl.id
        WHERE osl.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        diplomaJsonResponse(false, 'Objective log not found.');
    }

    diplomaJsonResponse(true, 'Objective log loaded.', ['log' => $row]);
}

function diplomaGetObjectiveSummary(PDO $pdo, array $payload): void
{
    $participantId = diplomaParticipantId($payload);
    $where = '';
    $params = [];

    if ($participantId !== '') {
        $where = 'WHERE osl.user_id = :participant_id';
        $params[':participant_id'] = $participantId;
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_submissions,
            SUM(osl.completed_successfully = 1) AS successful_submissions,
            ROUND(AVG(osl.final_objective_success_score), 2) AS average_final_score,
            ROUND(AVG(aie.ai_evaluation_score), 2) AS average_ai_score,
            SUM(aie.evaluation_status = 'completed') AS completed_ai_evaluations,
            SUM(aie.evaluation_status = 'failed') AS failed_ai_evaluations,
            SUM(aie.evaluation_status = 'manual_review') AS manual_review_evaluations
        FROM objective_success_logs osl
        LEFT JOIN ai_submission_evaluations aie ON aie.objective_log_id = osl.id
        {$where}
    ");
    $stmt->execute($params);

    diplomaJsonResponse(true, 'Objective summary loaded.', ['summary' => $stmt->fetch()]);
}

function diplomaJsonResponse(bool $ok, string $message, array $data = []): never
{
    echo json_encode(
        array_merge(['ok' => $ok, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
