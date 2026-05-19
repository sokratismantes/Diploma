<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * diploma_logger.php
 *
 * Καταγραφή ΜΟΝΟ objective success logs για το πείραμα:
 * video tutorial vs AI assistance στην υλοποίηση φθίνουσας μονοτονικής στοίβας.
 *
 * Βάση δεδομένων: diploma
 * Πίνακας: objective_success_logs
 *
 * Υποστηριζόμενα actions:
 * - save_objective_log
 * - save_submission
 * - get_objective_log
 * - get_objective_summary
 */

/**
 * =========================
 * DATABASE CONFIG
 * =========================
 */
const DB_HOST = 'localhost';
const DB_NAME = 'diploma';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

/**
 * Αν είναι true, δημιουργεί ΜΟΝΟ τον πίνακα objective_success_logs αν δεν υπάρχει.
 */
const AUTO_CREATE_TABLES = true;

/**
 * Default task id για το δικό σου πείραμα.
 */
const DEFAULT_TASK_ID = 'decreasing_monotonic_stack_v1';

/**
 * =========================
 * REQUEST HANDLER
 * =========================
 */
function diplomaLoggerHandleRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = getPdo();

        if (AUTO_CREATE_TABLES) {
            createTablesIfNotExist($pdo);
        }

        $payload = getJsonPayload();
        $action = $payload['action'] ?? $_POST['action'] ?? null;

        if (!$action) {
            jsonResponse(false, 'Missing action.');
        }

        switch ($action) {
            case 'save_objective_log':
            case 'save_submission':
                saveObjectiveLog($pdo, $payload);
                break;

            case 'get_objective_log':
                getObjectiveLog($pdo, $payload);
                break;

            case 'get_objective_summary':
                getObjectiveSummary($pdo, $payload);
                break;

            default:
                jsonResponse(false, 'Unknown action: ' . $action);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        jsonResponse(false, 'Server error: ' . $e->getMessage());
    }
}

/**
 * Αν το αρχείο κληθεί απευθείας, χειρίζεται το request.
 * Αν γίνει require_once μέσα από loginn.php, απλώς φορτώνει τις συναρτήσεις.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    diplomaLoggerHandleRequest();
}

/**
 * =========================
 * PDO CONNECTION
 * =========================
 */
function getPdo(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * =========================
 * TABLE
 * =========================
 */
function createTablesIfNotExist(PDO $pdo): void
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
            INDEX idx_completed_successfully (completed_successfully),
            INDEX idx_hardcoded_solution_detected (hardcoded_solution_detected)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

/**
 * =========================
 * ACTIONS
 * =========================
 */
function saveObjectiveLog(PDO $pdo, array $payload): void
{
    $userId = trim((string)($payload['user_id'] ?? getSessionUserId()));
    $condition = trim((string)($payload['condition'] ?? ''));
    $taskId = trim((string)($payload['task_id'] ?? DEFAULT_TASK_ID));
    $codeSnapshot = (string)($payload['code_snapshot'] ?? '');

    if ($userId === '') {
        jsonResponse(false, 'Missing user_id.');
    }

    if (!in_array($condition, ['ai_assistance', 'video_tutorial'], true)) {
        jsonResponse(false, 'Invalid condition. Use ai_assistance or video_tutorial.');
    }

    if (trim($taskId) === '') {
        $taskId = DEFAULT_TASK_ID;
    }

    if (trim($codeSnapshot) === '') {
        jsonResponse(false, 'Missing code_snapshot.');
    }

    $visibleTestPassed = toBoolInt($payload['visible_test_passed'] ?? false);
    $visibleTestOutput = isset($payload['visible_test_output'])
        ? (string)$payload['visible_test_output']
        : null;

    $hiddenTest1Passed = toBoolInt($payload['hidden_test_1_passed'] ?? false);
    $hiddenTest1Output = isset($payload['hidden_test_1_output'])
        ? (string)$payload['hidden_test_1_output']
        : null;

    $hiddenTest2Passed = toBoolInt($payload['hidden_test_2_passed'] ?? false);
    $hiddenTest2Output = isset($payload['hidden_test_2_output'])
        ? (string)$payload['hidden_test_2_output']
        : null;

    $decreasingPropertyPassed = toBoolInt($payload['decreasing_property_passed'] ?? false);

    /**
     * Αν δεν σταλεί hardcoded_solution_detected,
     * το υπολογίζουμε αντικειμενικά:
     *
     * hardcoded = true
     * όταν περνάει το visible test
     * αλλά αποτυγχάνει και στα δύο hidden tests.
     */
    if (array_key_exists('hardcoded_solution_detected', $payload)) {
        $hardcodedSolutionDetected = toBoolInt($payload['hardcoded_solution_detected']);
    } else {
        $hardcodedSolutionDetected = (
            $visibleTestPassed === 1 &&
            $hiddenTest1Passed === 0 &&
            $hiddenTest2Passed === 0
        ) ? 1 : 0;
    }

    /**
     * Objective success score:
     *
     * visible test = 40
     * hidden test 1 = 20
     * hidden test 2 = 20
     * decreasing property = 20
     */
    $score =
        ($visibleTestPassed * 40) +
        ($hiddenTest1Passed * 20) +
        ($hiddenTest2Passed * 20) +
        ($decreasingPropertyPassed * 20);

    /**
     * Αν σταλεί final_objective_success_score χειροκίνητα,
     * το αγνοούμε επίτηδες για να παραμείνει αντικειμενικός ο υπολογισμός.
     */

    /**
     * Αυστηρός κανόνας επιτυχίας:
     *
     * completed_successfully = true
     * μόνο αν περάσει όλα τα objective checks
     * και δεν ανιχνευθεί hardcoded λύση.
     */
    $completedSuccessfully = (
        $visibleTestPassed === 1 &&
        $hiddenTest1Passed === 1 &&
        $hiddenTest2Passed === 1 &&
        $decreasingPropertyPassed === 1 &&
        $hardcodedSolutionDetected === 0
    ) ? 1 : 0;

    $stmt = $pdo->prepare("
        INSERT INTO objective_success_logs (
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
            completed_successfully
        ) VALUES (
            :user_id,
            :condition,
            :task_id,
            :code_snapshot,

            :visible_test_passed,
            :visible_test_output,

            :hidden_test_1_passed,
            :hidden_test_1_output,

            :hidden_test_2_passed,
            :hidden_test_2_output,

            :decreasing_property_passed,
            :hardcoded_solution_detected,

            :final_objective_success_score,
            :completed_successfully
        )
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':condition' => $condition,
        ':task_id' => $taskId,
        ':code_snapshot' => $codeSnapshot,

        ':visible_test_passed' => $visibleTestPassed,
        ':visible_test_output' => $visibleTestOutput,

        ':hidden_test_1_passed' => $hiddenTest1Passed,
        ':hidden_test_1_output' => $hiddenTest1Output,

        ':hidden_test_2_passed' => $hiddenTest2Passed,
        ':hidden_test_2_output' => $hiddenTest2Output,

        ':decreasing_property_passed' => $decreasingPropertyPassed,
        ':hardcoded_solution_detected' => $hardcodedSolutionDetected,

        ':final_objective_success_score' => $score,
        ':completed_successfully' => $completedSuccessfully,
    ]);

    $logId = (int)$pdo->lastInsertId();

    jsonResponse(true, 'Objective log saved.', [
        'id' => $logId,
        'user_id' => $userId,
        'condition' => $condition,
        'task_id' => $taskId,

        'visible_test_passed' => (bool)$visibleTestPassed,
        'hidden_test_1_passed' => (bool)$hiddenTest1Passed,
        'hidden_test_2_passed' => (bool)$hiddenTest2Passed,
        'decreasing_property_passed' => (bool)$decreasingPropertyPassed,
        'hardcoded_solution_detected' => (bool)$hardcodedSolutionDetected,

        'final_objective_success_score' => $score,
        'completed_successfully' => (bool)$completedSuccessfully,
    ]);
}

function getObjectiveLog(PDO $pdo, array $payload): void
{
    $id = (int)($payload['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(false, 'Missing id.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM objective_success_logs
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonResponse(false, 'Objective log not found.');
    }

    jsonResponse(true, 'Objective log found.', $row);
}

function getObjectiveSummary(PDO $pdo, array $payload): void
{
    $condition = trim((string)($payload['condition'] ?? ''));

    $where = '';
    $params = [];

    if ($condition !== '') {
        if (!in_array($condition, ['ai_assistance', 'video_tutorial'], true)) {
            jsonResponse(false, 'Invalid condition. Use ai_assistance or video_tutorial.');
        }

        $where = 'WHERE `condition` = :condition';
        $params[':condition'] = $condition;
    }

    $stmt = $pdo->prepare("
        SELECT
            `condition`,
            COUNT(*) AS total_logs,
            AVG(final_objective_success_score) AS average_score,
            SUM(completed_successfully) AS completed_successfully_count,
            SUM(hardcoded_solution_detected) AS hardcoded_solution_count,
            SUM(visible_test_passed) AS visible_passed_count,
            SUM(hidden_test_1_passed) AS hidden_1_passed_count,
            SUM(hidden_test_2_passed) AS hidden_2_passed_count,
            SUM(decreasing_property_passed) AS decreasing_property_passed_count
        FROM objective_success_logs
        $where
        GROUP BY `condition`
    ");

    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse(true, 'Objective summary.', [
        'summary' => $rows,
    ]);
}

/**
 * =========================
 * HELPERS
 * =========================
 */
function getJsonPayload(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return $_POST ?: [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return $_POST ?: [];
    }

    return $data;
}

function getSessionUserId(): string
{
    if (!empty($_SESSION['user']['sub'])) {
        return (string)$_SESSION['user']['sub'];
    }

    if (!empty($_SESSION['participant_id'])) {
        return (string)$_SESSION['participant_id'];
    }

    return '';
}

function toBoolInt($value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value)) {
        return $value === 1 ? 1 : 0;
    }

    if (is_float($value)) {
        return ((int)$value) === 1 ? 1 : 0;
    }

    $normalized = strtolower(trim((string)$value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function jsonResponse(bool $success, string $message, array $data = []): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}