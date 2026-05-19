<?php
session_start();

/**
 * DATABASE CONFIG
 */
$dbHost = 'localhost';
$dbName = 'diploma';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Σφάλμα σύνδεσης με τη βάση δεδομένων.');
}

if (empty($_SESSION['participant_id'])) {
    header('Location: log.php');
    exit;
}

$sessionParticipantId = $_SESSION['participant_id'];

$stmt = $pdo->prepare("
    SELECT participant_id, assistance_type
    FROM Diploma
    WHERE participant_id = :participant_id
    ORDER BY created_at DESC
    LIMIT 1
");

$stmt->execute([
    ':participant_id' => $sessionParticipantId
]);

$participant = $stmt->fetch();

if (!$participant) {
    session_destroy();
    header('Location: log.php');
    exit;
}

$participantId = $participant['participant_id'];
$assistanceType = $participant['assistance_type'];

if (!in_array($assistanceType, ['ai', 'video'], true)) {
    die('Μη έγκυρος τύπος πειράματος.');
}

$questionnaireType = 'pre';

$assistanceLabel = $assistanceType === 'ai'
    ? 'AI Assistance'
    : 'Video Tutorial';

$nextPage = $assistanceType === 'ai'
    ? 'loginn.php'
    : 'login.php';

$questions = [
    [
        'code' => 'SE1',
        'text' => 'Μπορώ να χωρίσω την υλοποίηση μιας δομής δεδομένων σε μικρότερα βήματα.',
        'purpose' => 'Διάσπαση υλοποίησης'
    ],
    [
        'code' => 'SE2',
        'text' => 'Μπορώ να καταλάβω τι είναι μια stack και ποιες βασικές λειτουργίες έχει.',
        'purpose' => 'Βασική κατανόηση stack'
    ],
    [
        'code' => 'SE3',
        'text' => 'Μπορώ να καταλάβω τι σημαίνει μια stack να διατηρείται μονοτονική.',
        'purpose' => 'Κατανόηση μονοτονικής ιδιότητας'
    ],
    [
        'code' => 'SE4',
        'text' => 'Μπορώ να καταλάβω τη διαφορά ανάμεσα σε αύξουσα και φθίνουσα μονοτονική στοίβα.',
        'purpose' => 'Κατανόηση τύπων μονοτονίας'
    ],
    [
        'code' => 'SE5',
        'text' => 'Μπορώ να σχεδιάσω τη βασική λογική λειτουργίας μιας μονοτονικής στοίβας πριν γράψω κώδικα.',
        'purpose' => 'Σχεδιασμός λύσης'
    ],
    [
        'code' => 'SE6',
        'text' => 'Μπορώ να περιγράψω με δικά μου λόγια πώς αλλάζει η στοίβα όταν εισάγεται ένα νέο στοιχείο.',
        'purpose' => 'Κατανόηση συμπεριφοράς δομής'
    ],
    [
        'code' => 'SE7',
        'text' => 'Μπορώ να υλοποιήσω λειτουργία εισαγωγής στοιχείου ώστε η στοίβα να παραμένει μονοτονική.',
        'purpose' => 'Κεντρική υλοποίηση'
    ],
    [
        'code' => 'SE8',
        'text' => 'Μπορώ να παρακολουθήσω βήμα προς βήμα την κατάσταση της στοίβας για μια μικρή ακολουθία εισόδων.',
        'purpose' => 'Tracing / νοητική εκτέλεση'
    ],
    [
        'code' => 'SE9',
        'text' => 'Μπορώ να εντοπίσω και να διορθώσω λάθη στην υλοποίηση μιας μονοτονικής στοίβας.',
        'purpose' => 'Debugging'
    ],
    [
        'code' => 'SE10',
        'text' => 'Πιστεύω ότι μπορώ να υλοποιήσω ξανά μια μονοτονική στοίβα στο μέλλον.',
        'purpose' => 'Μελλοντική αυτοπεποίθηση'
    ],
];

$scaleLabels = [
    1 => 'Διαφωνώ απόλυτα',
    2 => 'Διαφωνώ',
    3 => 'Ούτε συμφωνώ ούτε διαφωνώ',
    4 => 'Συμφωνώ',
    5 => 'Συμφωνώ απόλυτα'
];

$errors = [];
$submitted = false;
$result = null;
$redirectAfterSubmit = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;

    $answers = $_POST['answers'] ?? [];

    foreach ($questions as $question) {
        $code = $question['code'];

        if (!isset($answers[$code]) || !in_array((int)$answers[$code], [1, 2, 3, 4, 5], true)) {
            $errors[] = 'Πρέπει να απαντήσεις σε όλες τις ερωτήσεις.';
            break;
        }
    }

    if (!$errors) {
        $sum = 0;
        $cleanAnswers = [];

        foreach ($questions as $question) {
            $code = $question['code'];
            $value = (int)$answers[$code];

            $sum += $value;
            $cleanAnswers[$code] = $value;
        }

        $mean = round($sum / count($questions), 2);

        /**
         * Self-efficacy interpretation indicators.
         * Δεν αποθηκεύονται ως επιπλέον στήλες στον πίνακα self_efficacy.
         * Υπολογίζονται από τις απαντήσεις SE1-SE10 για εμφάνιση/ερμηνεία.
         */
        $conceptualScore = round((
            $cleanAnswers['SE2'] +
            $cleanAnswers['SE3'] +
            $cleanAnswers['SE4']
        ) / 3, 2);

        $planningReasoningScore = round((
            $cleanAnswers['SE1'] +
            $cleanAnswers['SE5'] +
            $cleanAnswers['SE6'] +
            $cleanAnswers['SE8']
        ) / 4, 2);

        $implementationConfidenceScore = round((
            $cleanAnswers['SE7'] +
            $cleanAnswers['SE9'] +
            $cleanAnswers['SE10']
        ) / 3, 2);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO self_efficacy (
                    participant_id,
                    questionnaire_type,
                    group_type,
                    se1,
                    se2,
                    se3,
                    se4,
                    se5,
                    se6,
                    se7,
                    se8,
                    se9,
                    se10,
                    total_score,
                    mean_score,
                    submitted_at
                ) VALUES (
                    :participant_id,
                    :questionnaire_type,
                    :group_type,
                    :se1,
                    :se2,
                    :se3,
                    :se4,
                    :se5,
                    :se6,
                    :se7,
                    :se8,
                    :se9,
                    :se10,
                    :total_score,
                    :mean_score,
                    NOW()
                )
            ");

            $stmt->execute([
                ':participant_id' => $participantId,
                ':questionnaire_type' => $questionnaireType,
                ':group_type' => $assistanceType,
                ':se1' => $cleanAnswers['SE1'],
                ':se2' => $cleanAnswers['SE2'],
                ':se3' => $cleanAnswers['SE3'],
                ':se4' => $cleanAnswers['SE4'],
                ':se5' => $cleanAnswers['SE5'],
                ':se6' => $cleanAnswers['SE6'],
                ':se7' => $cleanAnswers['SE7'],
                ':se8' => $cleanAnswers['SE8'],
                ':se9' => $cleanAnswers['SE9'],
                ':se10' => $cleanAnswers['SE10'],
                ':total_score' => $sum,
                ':mean_score' => $mean,
            ]);

            $result = [
                'participant_id' => $participantId,
                'questionnaire_type' => $questionnaireType,
                'group_type' => $assistanceType,
                'total_score' => $sum,
                'mean_score' => $mean,
                'pre_total_score' => $sum,
                'pre_mean_score' => $mean,
                'pre_conceptual_score' => $conceptualScore,
                'pre_planning_reasoning_score' => $planningReasoningScore,
                'pre_implementation_confidence_score' => $implementationConfidenceScore,
            ];

            $_SESSION['pre_questionnaire_done'] = true;
            $redirectAfterSubmit = true;

        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Έχει ήδη υποβληθεί το pre ερωτηματολόγιο για αυτόν τον συμμετέχοντα.';
            } else {
                $errors[] = 'Προέκυψε σφάλμα κατά την αποθήκευση στη βάση.';
            }
        }
    }
}

function isAnswerChecked(string $code, int $value): string
{
    return (isset($_POST['answers'][$code]) && (int)$_POST['answers'][$code] === $value) ? 'checked' : '';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre Self-Efficacy Questionnaire</title>

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #07101f;
            --bg-2: #050b16;
            --surface: rgba(13, 20, 38, 0.84);
            --text: #e8eefb;
            --text-soft: #bfd0ef;
            --text-muted: #8393b3;
            --accent: #5b8cff;
            --success: #22c55e;
            --danger: #ef4444;
            --border: rgba(148,163,184,0.14);
            --border-strong: rgba(148,163,184,0.24);
            --shadow-md: 0 20px 48px rgba(0,0,0,0.24);
            --radius-lg: 24px;
            --transition-fast: 180ms ease;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
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

        .app-shell {
            max-width: 1120px;
            margin: 0 auto;
            padding: 1.2rem;
            min-height: 100vh;
        }

        .card {
            background: var(--surface);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            border-radius: var(--radius-lg);
            padding: 1rem;
            overflow: hidden;
        }

        .page-hero {
            margin-bottom: 1rem;
            background:
                radial-gradient(circle at top left, rgba(91,140,255,.14), transparent 30%),
                radial-gradient(circle at top right, rgba(34,197,94,.08), transparent 24%),
                var(--surface);
        }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .eyebrow {
            color: var(--accent);
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        h1 {
            margin: .25rem 0 0;
            font-size: clamp(1.45rem, 2.4vw, 2.2rem);
            line-height: 1.15;
            letter-spacing: -.03em;
        }

        .hero-text {
            max-width: 760px;
            margin: .8rem 0 0;
            color: var(--text-soft);
            line-height: 1.65;
            font-size: .94rem;
        }

        .badge {
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

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .9rem;
            margin-bottom: 1rem;
        }

        .field {
            border-radius: 18px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.03);
            padding: .95rem;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label.field-label {
            display: block;
            font-size: .82rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: .55rem;
        }

        .readonly-value {
            width: 100%;
            min-height: 42px;
            border-radius: 14px;
            border: 1px solid var(--border-strong);
            background: rgba(7,11,22,.84);
            color: var(--text);
            padding: .72rem .9rem;
            font-size: .9rem;
            display: flex;
            align-items: center;
            font-weight: 800;
        }

        .field-help {
            color: var(--text-muted);
            font-size: .78rem;
            line-height: 1.5;
            margin-top: .45rem;
        }

        .scale-card {
            margin-top: 2rem;
            margin-bottom: 1rem;
            border-radius: 18px;
            border: 1px solid rgba(91,140,255,.20);
            background: rgba(91,140,255,.08);
            padding: .95rem;
        }

        .scale-title {
            font-size: .86rem;
            font-weight: 800;
            margin-bottom: .75rem;
        }

        .scale-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: .5rem;
        }

        .scale-item {
            border-radius: 14px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.04);
            padding: .65rem;
            text-align: center;
        }

        .scale-number {
            width: 30px;
            height: 30px;
            display: inline-grid;
            place-items: center;
            border-radius: 999px;
            background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
            color: #fff;
            font-weight: 800;
            font-size: .82rem;
            margin-bottom: .4rem;
        }

        .scale-label {
            color: var(--text-soft);
            font-size: .72rem;
            line-height: 1.35;
        }

        .section-title {
            margin: 1rem 0 .75rem;
        }

        .section-title h2 {
            margin: 0;
            font-size: 1.05rem;
        }

        .section-title p {
            margin: .25rem 0 0;
            color: var(--text-muted);
            font-size: .82rem;
            line-height: 1.55;
        }

        .question-list {
            display: flex;
            flex-direction: column;
            gap: .85rem;
        }

        .question-card {
            border-radius: 20px;
            border: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.02));
            padding: .95rem;
        }

        .question-head {
            display: flex;
            gap: .75rem;
            align-items: flex-start;
            margin-bottom: .8rem;
        }

        .question-code {
            width: 46px;
            height: 34px;
            border-radius: 12px;
            background: rgba(91,140,255,.12);
            border: 1px solid rgba(91,140,255,.18);
            color: #dbeafe;
            display: grid;
            place-items: center;
            font-weight: 800;
            font-size: .78rem;
            flex: 0 0 auto;
        }

        .question-text {
            color: var(--text);
            font-weight: 750;
            line-height: 1.55;
            font-size: .92rem;
        }

        .question-purpose {
            color: var(--text-muted);
            font-size: .76rem;
            margin-top: .28rem;
        }

        .answer-options {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: .45rem;
        }

        .answer-option {
            position: relative;
            min-height: 54px;
            border-radius: 16px;
            border: 1px solid var(--border);
            background: rgba(7,11,22,.55);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .25rem;
            cursor: pointer;
            text-align: center;
            padding: .45rem;
        }

        .answer-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .answer-number {
            width: 25px;
            height: 25px;
            border-radius: 999px;
            display: grid;
            place-items: center;
            background: rgba(255,255,255,.06);
            color: var(--text-soft);
            font-weight: 800;
            font-size: .76rem;
            border: 1px solid var(--border);
        }

        .answer-label {
            color: var(--text-muted);
            font-size: .66rem;
            line-height: 1.2;
        }

        .answer-option:has(input:checked) {
            background: rgba(91,140,255,.16);
            border-color: rgba(91,140,255,.52);
            box-shadow: 0 0 0 4px rgba(91,140,255,.08);
        }

        .answer-option:has(input:checked) .answer-number {
            background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
            color: #fff;
            border-color: transparent;
        }

        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .8rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        button.primary {
            border-radius: 12px;
            border: 1px solid transparent;
            height: 42px;
            padding: 0 1.05rem;
            font-size: .84rem;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
            color: #fff;
            box-shadow: 0 10px 26px rgba(59,130,246,.28);
        }

        .muted-note {
            color: var(--text-muted);
            font-size: .78rem;
            line-height: 1.5;
        }

        .alert {
            border-radius: 18px;
            padding: .9rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            line-height: 1.55;
            font-size: .86rem;
        }

        .alert.error {
            border-color: rgba(239,68,68,.25);
            background: rgba(239,68,68,.08);
            color: #fecaca;
        }

        .alert.success {
            border-color: rgba(34,197,94,.28);
            background: rgba(34,197,94,.09);
            color: #bbf7d0;
        }

        .result-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .75rem;
            margin-top: .9rem;
        }

        .result-box {
            border-radius: 18px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.04);
            padding: .85rem;
        }

        .result-label {
            color: var(--text-muted);
            font-size: .74rem;
            margin-bottom: .25rem;
        }

        .result-value {
            color: var(--text);
            font-weight: 900;
            font-size: 1rem;
        }

        .loading-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: rgba(3, 8, 20, 0.38);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.35s ease, visibility 0.35s ease;
            z-index: 9999;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-card {
            width: min(92vw, 420px);
            border-radius: 26px;
            border: 1px solid rgba(148,163,184,0.18);
            background: rgba(10, 18, 34, 0.82);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.42);
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            text-align: center;
        }

        .loading-image {
            width: 180px;
            max-width: 55vw;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 12px 24px rgba(59,130,246,0.25));
            animation: floaty 1.8s ease-in-out infinite;
        }

        .loading-title {
            margin: 0;
            font-size: 1.12rem;
            font-weight: 800;
            color: var(--text);
        }

        .loading-text {
            margin: 0;
            font-size: 0.9rem;
            line-height: 1.65;
            color: var(--text-soft);
        }

        .loading-spinner {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.16);
            border-top-color: #5b8cff;
            animation: spin 0.9s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes floaty {
            0%, 100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-6px);
            }
        }

        @media (max-width: 820px) {
            .form-grid,
            .scale-row,
            .answer-options,
            .result-grid {
                grid-template-columns: 1fr;
            }

            .answer-option {
                flex-direction: row;
                justify-content: flex-start;
                text-align: left;
                padding: .65rem .75rem;
            }

            .scale-item {
                display: flex;
                align-items: center;
                gap: .7rem;
                text-align: left;
            }

            .loading-image {
                width: 100px;
            }
        }
    </style>
</head>

<body>
<div class="app-shell">

    <header class="card page-hero">
        <div class="hero-top">
            <div>
                <div class="eyebrow">Monotonic Stack Experiment</div>
                <h1>Pre Self-Efficacy Questionnaire</h1>
            </div>

            <div class="badge">Pre Questionnaire</div>
        </div>

        <p class="hero-text">
            Το ερωτηματολόγιο μετρά την αυτοπεποίθησή σας πριν την εκπαιδευτική παρέμβαση
            και πριν την υλοποίηση της μονοτονικής στοίβας. Δεν υπάρχουν σωστές ή λάθος απαντήσεις.
        </p>

        <div class="scale-card">
            <div class="scale-title">Κλίμακα απαντήσεων</div>

            <div class="scale-row">
                <?php foreach ($scaleLabels as $value => $label): ?>
                    <div class="scale-item">
                        <div class="scale-number"><?= $value ?></div>
                        <div class="scale-label"><?= e($label) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </header>

    <?php if ($submitted && $errors): ?>
        <div class="alert error">
            <strong>Δεν ολοκληρώθηκε η υποβολή.</strong>
            <br>
            <?= e($errors[0]) ?>
        </div>
    <?php endif; ?>

    <?php if ($submitted && !$errors && $result): ?>
        <div class="alert success">
            <strong>Η υποβολή ολοκληρώθηκε επιτυχώς.</strong>
            <br>
            Οι απαντήσεις αποθηκεύτηκαν στη βάση δεδομένων.

            <div style="margin-top: .9rem;">
                <button type="button" class="primary" id="continueBtn">
                    Συνέχεια
                </button>
            </div>
        </div>

        <div class="card" style="margin-bottom: 1rem;">
            <div class="section-title">
                <h2>Αποτέλεσμα υποβολής</h2>
                <p>Πάτησε <strong>Συνέχεια</strong> για να μεταφερθείς στο αντίστοιχο περιβάλλον του πειράματος.</p>
            </div>

            <div class="result-grid">
                <div class="result-box">
                    <div class="result-label">Participant ID</div>
                    <div class="result-value"><?= e($result['participant_id']) ?></div>
                </div>

                <div class="result-box">
                    <div class="result-label">Τύπος</div>
                    <div class="result-value">Pre</div>
                </div>

                <div class="result-box">
                    <div class="result-label">Είδος πειράματος</div>
                    <div class="result-value"><?= e($assistanceLabel) ?></div>
                </div>

                <div class="result-box">
                    <div class="result-label">Mean Score</div>
                    <div class="result-value"><?= e((string)$result['mean_score']) ?> / 5</div>
                </div>

                <div class="result-box">
                    <div class="result-label">Conceptual Score</div>
                    <div class="result-value"><?= e((string)$result['pre_conceptual_score']) ?> / 5</div>
                </div>

                <div class="result-box">
                    <div class="result-label">Planning / Reasoning</div>
                    <div class="result-value"><?= e((string)$result['pre_planning_reasoning_score']) ?> / 5</div>
                </div>

                <div class="result-box">
                    <div class="result-label">Implementation Confidence</div>
                    <div class="result-value"><?= e((string)$result['pre_implementation_confidence_score']) ?> / 5</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$submitted || $errors): ?>
    <main class="card">
        <form method="post" action="">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label">Κωδικός συμμετέχοντα</label>
                    <div class="readonly-value"><?= e($participantId) ?></div>
                    <div class="field-help">
                        Ο κωδικός ανακτήθηκε αυτόματα από τον πίνακα Diploma.
                    </div>
                </div>

                <div class="field">
                    <label class="field-label">Είδος πειράματος</label>
                    <div class="readonly-value"><?= e($assistanceLabel) ?></div>
                    <div class="field-help">
                        Το είδος πειράματος ανακτήθηκε αυτόματα από τον πίνακα Diploma.
                    </div>
                </div>
            </div>

            <div class="section-title">
                <h2>Self-Efficacy ερωτήσεις</h2>
                <p>Απάντησε με βάση το πόσο ικανός/ή νιώθεις αυτή τη στιγμή, πριν την παρέμβαση.</p>
            </div>

            <div class="question-list">
                <?php foreach ($questions as $question): ?>
                    <section class="question-card">
                        <div class="question-head">
                            <div class="question-code"><?= e($question['code']) ?></div>

                            <div>
                                <div class="question-text">
                                    <?= e($question['text']) ?>
                                </div>

                                <div class="question-purpose">
                                    Μετράει: <?= e($question['purpose']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="answer-options">
                            <?php foreach ($scaleLabels as $value => $label): ?>
                                <label class="answer-option">
                                    <input
                                        type="radio"
                                        name="answers[<?= e($question['code']) ?>]"
                                        value="<?= $value ?>"
                                        <?= isAnswerChecked($question['code'], $value) ?>
                                        required
                                    >
                                    <span class="answer-number"><?= $value ?></span>
                                    <span class="answer-label"><?= e($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <div class="muted-note">
                    Το συνολικό pre self-efficacy score υπολογίζεται ως μέσος όρος των SE1–SE10.
                </div>

                <button type="submit" class="primary">
                    Υποβολή Pre Ερωτηματολογίου
                </button>
            </div>
        </form>
    </main>
    <?php endif; ?>
</div>

<div class="loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="loading-card">
        <img src="welcome.png" alt="Welcome" class="loading-image" />
        <h2 class="loading-title">Το pre ερωτηματολόγιο ολοκληρώθηκε</h2>
        <p class="loading-text">Μεταφέρεσαι τώρα στο αντίστοιχο περιβάλλον του πειράματος.</p>
        <div class="loading-spinner" aria-hidden="true"></div>
    </div>
</div>

<?php if ($redirectAfterSubmit && !$errors): ?>
<script>
    (function () {
        const continueBtn = document.getElementById('continueBtn');
        const overlay = document.getElementById('loadingOverlay');
        const nextPage = <?= json_encode($nextPage) ?>;

        if (!continueBtn || !overlay) return;

        continueBtn.addEventListener('click', function () {
            continueBtn.disabled = true;
            continueBtn.textContent = 'Φόρτωση...';

            overlay.classList.add('active');
            overlay.setAttribute('aria-hidden', 'false');

            setTimeout(function () {
                window.location.href = nextPage;
            }, 1200);
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
