<?php
// log.php
session_start();

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

$errorMsg = '';
$redirectTarget = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $participantId  = trim($_POST['participant_id'] ?? '');
    $name           = trim($_POST['name'] ?? '');
    $studyYear      = trim($_POST['study_year'] ?? '');
    $experience     = trim($_POST['experience'] ?? '');
    $assistanceType = trim($_POST['assistance_type'] ?? '');

    if ($participantId === '') {
        $errorMsg = 'Ο κωδικός συμμετέχοντα είναι υποχρεωτικός.';
    } elseif (!in_array($assistanceType, ['ai', 'video'], true)) {
        $errorMsg = 'Παρακαλώ επίλεξε τύπο assistance.';
    } else {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            $stmt = $pdo->prepare("
                INSERT INTO Diploma (
                    participant_id,
                    name,
                    study_year,
                    experience,
                    assistance_type,
                    created_at
                ) VALUES (
                    :participant_id,
                    :name,
                    :study_year,
                    :experience,
                    :assistance_type,
                    NOW()
                )
            ");

            $stmt->execute([
                ':participant_id'  => $participantId,
                ':name'            => $name,
                ':study_year'      => $studyYear,
                ':experience'      => $experience,
                ':assistance_type' => $assistanceType,
            ]);

            $_SESSION['participant_id']   = $participantId;
            $_SESSION['participant_name'] = $name;
            $_SESSION['study_year']       = $studyYear;
            $_SESSION['experience']       = $experience;
            $_SESSION['assistance_type']  = $assistanceType;
            $_SESSION['signup_success']   = true;

            /*
             * Πλέον και οι δύο ομάδες πηγαίνουν πρώτα στο pre ερωτηματολόγιο.
             * Από εκεί, μετά την υποβολή, μπορείς να τους στείλεις στο αντίστοιχο περιβάλλον.
             */
            $redirectTarget = 'pre_questions.php';

        } catch (PDOException $e) {
            $errorMsg = 'Προέκυψε σφάλμα κατά την αποθήκευση στη βάση. Επικοινώνησε με τον υπεύθυνο.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Participant Entry – Programming Tasks Environment</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --bg: #07101f;
      --bg-2: #050b16;
      --surface: rgba(13, 20, 38, 0.84);
      --text: #e8eefb;
      --text-soft: #bfd0ef;
      --text-muted: #8393b3;
      --border: rgba(148,163,184,0.14);
      --shadow-sm: 0 10px 30px rgba(0,0,0,0.16);
      --shadow-md: 0 20px 48px rgba(0,0,0,0.24);
      --radius-lg: 24px;
      --transition-fast: 180ms ease;
      --glass-bg: rgba(13, 20, 38, 0.62);
      --glass-bg-strong: rgba(13, 20, 38, 0.82);
      --glass-top: rgba(255,255,255,0.08);
      --glass-top-strong: rgba(255,255,255,0.12);
      --glass-border: rgba(148,163,184,0.18);
      --glass-shadow: 0 14px 32px rgba(0,0,0,0.22);
      --glass-focus: rgba(91,140,255,0.42);
    }

    * {
      box-sizing: border-box;
    }

    html,
    body {
      min-height: 100%;
    }

    body {
      margin: 0;
      font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.2rem;
      position: relative;
    }

    body.loading-active .shell,
    body.loading-active .top-center-brand {
      filter: blur(10px);
      transition: filter 0.35s ease;
      pointer-events: none;
      user-select: none;
    }

    .top-center-brand {
      position: fixed;
      top: 24px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      gap: 1rem;
      z-index: 20;
      pointer-events: none;
    }

    .top-center-brand-image {
      width: 72px;
      height: 72px;
      object-fit: cover;
      border-radius: 22px;
      box-shadow: 0 14px 30px rgba(59,130,246,0.28);
      flex: 0 0 auto;
    }

    .top-center-brand-text {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
      text-align: left;
    }

    .top-center-brand-text span:first-child {
      font-size: 0.76rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--text-muted);
    }

    .top-center-brand-text span:last-child {
      font-weight: 700;
      font-size: 1.08rem;
      letter-spacing: -0.02em;
      color: var(--text);
    }

    .shell {
      width: 100%;
      max-width: 1200px;
      display: grid;
      grid-template-columns: 620px minmax(0, 1fr);
      gap: 1rem;
      align-items: start;
      margin-top: 100px;
      transition: filter 0.35s ease;
    }

    @media (max-width: 900px) {
      .shell {
        grid-template-columns: 1fr;
      }
    }

    .form-card {
      background:
        radial-gradient(circle at top left, rgba(91,140,255,0.08), transparent 26%),
        var(--surface);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-md);
      border-radius: var(--radius-lg);
      overflow: visible;
      padding: 1.3rem;
      display: flex;
      flex-direction: column;
      gap: 0.95rem;
    }

    .hero {
      padding: 0.2rem 0;
      background: transparent;
      border: none;
      box-shadow: none;
      overflow: visible;
    }

    .hero-inner {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      height: 100%;
    }

    .error-box {
      padding: 0.75rem 0.9rem;
      border-radius: 14px;
      border: 1px solid rgba(248,113,113,0.32);
      background: rgba(127,29,29,0.32);
      color: #fee2e2;
      font-size: 0.82rem;
      line-height: 1.5;
    }

    .form-header {
      display: flex;
      flex-direction: column;
      gap: 0.2rem;
    }

    .form-title {
      font-size: 1rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.55rem;
      letter-spacing: -0.01em;
    }

    .form-title-badge {
      width: 30px;
      height: 30px;
      border-radius: 12px;
      background: rgba(91,140,255,0.12);
      border: 1px solid rgba(91,140,255,0.18);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 0.95rem;
      flex: 0 0 auto;
    }

    .form-subtitle {
      margin: 0;
      font-size: 0.84rem;
      color: var(--text-muted);
      line-height: 1.55;
    }

    form {
      display: flex;
      flex-direction: column;
      gap: 0.8rem;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      font-size: 0.84rem;
      position: relative;
    }

    .field label {
      color: var(--text);
      font-weight: 600;
    }

    .field span.helper {
      font-size: 0.76rem;
      color: var(--text-muted);
      line-height: 1.45;
    }

    .field-row {
      display: flex;
      gap: 0.7rem;
      flex-wrap: wrap;
    }

    .field-row .field {
      flex: 1;
      min-width: 0;
    }

    input[type="text"] {
      width: 100%;
      background:
        linear-gradient(180deg, var(--glass-top), rgba(255,255,255,0.03)),
        var(--glass-bg);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border-radius: 12px;
      border: 1px solid var(--glass-border);
      padding: 0.72rem 0.9rem;
      font-size: 0.86rem;
      color: var(--text);
      outline: none;
      transition: border-color 0.15s ease-out, box-shadow 0.15s ease-out, background 0.15s ease-out, transform 0.15s ease-out;
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,0.05),
        var(--glass-shadow);
    }

    input::placeholder {
      color: rgba(148,163,184,0.78);
    }

    input:focus {
      border-color: var(--glass-focus);
      box-shadow:
        0 0 0 4px rgba(91,140,255,0.10),
        0 10px 28px rgba(59,130,246,0.12);
      background:
        linear-gradient(180deg, var(--glass-top-strong), rgba(255,255,255,0.04)),
        rgba(13, 20, 38, 0.72);
    }

    .native-select-hidden {
      position: absolute;
      opacity: 0;
      pointer-events: none;
      width: 1px;
      height: 1px;
      overflow: hidden;
    }

    .glass-select {
      position: relative;
      width: 100%;
    }

    .glass-select-trigger {
      width: 100%;
      border: 1px solid var(--glass-border);
      border-radius: 12px;
      background:
        linear-gradient(180deg, var(--glass-top), rgba(255,255,255,0.03)),
        var(--glass-bg);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      color: var(--text);
      min-height: 46px;
      padding: 0.72rem 2.9rem 0.72rem 0.9rem;
      font-size: 0.86rem;
      font-family: inherit;
      text-align: left;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.7rem;
      cursor: pointer;
      outline: none;
      box-shadow:
        inset 0 1px 0 rgba(255,255,255,0.05),
        var(--glass-shadow);
    }

    .glass-select-value {
      color: var(--text);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .glass-select-value.is-placeholder {
      color: rgba(191,208,239,0.78);
    }

    .glass-select-arrow {
      position: absolute;
      right: 0.95rem;
      top: 50%;
      width: 10px;
      height: 10px;
      border-right: 2px solid rgba(191,208,239,0.9);
      border-bottom: 2px solid rgba(191,208,239,0.9);
      transform: translateY(-65%) rotate(45deg);
      pointer-events: none;
      transition: transform 0.18s ease, opacity 0.18s ease;
      opacity: 0.92;
    }

    .glass-select.open .glass-select-arrow {
      transform: translateY(-35%) rotate(-135deg);
    }

    .glass-select-menu {
      position: absolute;
      bottom: calc(100% + 0.5rem);
      top: auto;
      left: 0;
      right: 0;
      z-index: 50;
      border-radius: 18px;
      padding: 0.45rem;
      border: 1px solid rgba(148,163,184,0.18);
      background:
        radial-gradient(circle at bottom left, rgba(91,140,255,0.14), transparent 35%),
        linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03)),
        rgba(9, 15, 28, 0.82);
      backdrop-filter: blur(22px);
      -webkit-backdrop-filter: blur(22px);
      box-shadow:
        0 22px 54px rgba(0,0,0,0.34),
        inset 0 1px 0 rgba(255,255,255,0.06);
      opacity: 0;
      visibility: hidden;
      transform: translateY(6px) scale(0.98);
      transition: opacity 0.18s ease, visibility 0.18s ease, transform 0.18s ease;
      max-height: 240px;
      overflow: auto;
    }

    .glass-select.open .glass-select-menu {
      opacity: 1;
      visibility: visible;
      transform: translateY(0) scale(1);
    }

    .glass-select-option {
      width: 100%;
      border: 1px solid transparent;
      background: transparent;
      color: var(--text);
      font-family: inherit;
      font-size: 0.84rem;
      text-align: left;
      border-radius: 14px;
      padding: 0.72rem 0.85rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.8rem;
    }

    .glass-select-option:hover {
      background: rgba(255,255,255,0.06);
      border-color: rgba(91,140,255,0.18);
    }

    .glass-select-option.is-selected {
      background: rgba(91,140,255,0.12);
      border-color: rgba(91,140,255,0.22);
      color: #edf4ff;
    }

    .glass-select-option-check {
      opacity: 0;
      font-size: 0.78rem;
      color: #b8d0ff;
      flex: 0 0 auto;
    }

    .glass-select-option.is-selected .glass-select-option-check {
      opacity: 1;
    }

    .consent-box {
      margin-top: 0.1rem;
      font-size: 0.79rem;
      color: var(--text-soft);
      border-radius: 16px;
      border: 1px solid var(--border);
      padding: 0.85rem 0.9rem;
      background: rgba(255,255,255,0.03);
      line-height: 1.55;
    }

    .btn {
      border-radius: 12px;
      width: 100%;
      border: 1px solid transparent;
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      color: #fff;
      height: 48px;
      padding: 0 1.1rem;
      font-size: 0.86rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      cursor: pointer;
      text-decoration: none;
      box-shadow: 0 10px 26px rgba(59,130,246,0.28);
      transition: transform var(--transition-fast), box-shadow var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast);
      white-space: nowrap;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 34px rgba(59,130,246,0.36);
    }

    .assistance-options {
      display: flex;
      flex-direction: column;
      gap: 1rem;
      margin-top: 0.6rem;
    }

    .assistance-box {
      border-radius: 22px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.03);
      padding: 1.25rem 1.4rem;
      display: flex;
      flex-direction: column;
      gap: 0.9rem;
      box-shadow: var(--shadow-sm);
    }

    .assistance-box-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text);
    }

    .assistance-box-text {
      font-size: 0.8rem;
      color: var(--text-soft);
      line-height: 1.7;
    }

    .form-footer {
      margin-top: 0.2rem;
      font-size: 0.79rem;
      color: var(--text-muted);
      line-height: 1.5;
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
      letter-spacing: -0.02em;
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

    @media (max-width: 700px) {
      .top-center-brand {
        top: 16px;
        gap: 0.75rem;
      }

      .top-center-brand-image {
        width: 60px;
        height: 60px;
        border-radius: 18px;
      }

      .top-center-brand-text span:last-child {
        font-size: 0.96rem;
      }

      .loading-card {
        padding: 1.6rem 1.2rem;
      }

      .loading-image {
        width: 100px;
      }
    }
  </style>
</head>

<body>
  <div class="top-center-brand">
    <img src="welcome.png" alt="Logo" class="top-center-brand-image" />
    <div class="top-center-brand-text">
      <span>PROGRAMMING STUDY</span>
      <span>Interactive Tasks Environment</span>
    </div>
  </div>

  <div class="shell">

    <section class="form-card">
      <?php if (!empty($errorMsg)): ?>
        <div class="error-box">
          <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <div class="form-header">
        <div class="form-title">
          <span class="form-title-badge">🔑</span>
          <span>Είσοδος συμμετέχοντα</span>
        </div>
        <p class="form-subtitle">
          Συμπλήρωσε τον κωδικό συμμετοχής και λίγες βασικές πληροφορίες για τις ανάγκες της μελέτης.
        </p>
      </div>

      <form method="post" action="" id="participantForm">
        <div class="field">
          <label for="participant_id">Κωδικός συμμετέχοντα *</label>
          <input
            type="text"
            id="participant_id"
            name="participant_id"
            required
            placeholder="π.χ. P123, ανώνυμο id που σου δόθηκε"
            value="<?= htmlspecialchars($_POST['participant_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          />
          <span class="helper">Αυτός ο κωδικός θα χρησιμοποιηθεί για ανώνυμη αντιστοίχιση pre, task και post αποτελεσμάτων.</span>
        </div>

        <div class="field">
          <label for="name">Όνομα (προαιρετικό)</label>
          <input
            type="text"
            id="name"
            name="name"
            placeholder="Μπορείς να το αφήσεις κενό"
            value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          />
        </div>

        <div class="field-row">
          <div class="field">
            <label for="study_year">Έτος σπουδών</label>

            <select id="study_year" name="study_year" class="native-select-hidden" tabindex="-1" aria-hidden="true">
              <option value="" <?= (($_POST['study_year'] ?? '') === '') ? 'selected' : '' ?>></option>
              <option value="1" <?= (($_POST['study_year'] ?? '') === '1') ? 'selected' : '' ?>>1ο</option>
              <option value="2" <?= (($_POST['study_year'] ?? '') === '2') ? 'selected' : '' ?>>2ο</option>
              <option value="3" <?= (($_POST['study_year'] ?? '') === '3') ? 'selected' : '' ?>>3ο</option>
              <option value="4" <?= (($_POST['study_year'] ?? '') === '4') ? 'selected' : '' ?>>4ο</option>
              <option value="5+" <?= (($_POST['study_year'] ?? '') === '5+') ? 'selected' : '' ?>>5ο ή μεγαλύτερο</option>
            </select>

            <div class="glass-select" data-target="study_year">
              <button type="button" class="glass-select-trigger" aria-expanded="false">
                <span class="glass-select-value is-placeholder">Επίλεξε...</span>
                <span class="glass-select-arrow"></span>
              </button>

              <div class="glass-select-menu" role="listbox">
                <button type="button" class="glass-select-option" data-value="1">
                  <span>1ο</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
                <button type="button" class="glass-select-option" data-value="2">
                  <span>2ο</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
                <button type="button" class="glass-select-option" data-value="3">
                  <span>3ο</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
                <button type="button" class="glass-select-option" data-value="4">
                  <span>4ο</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
                <button type="button" class="glass-select-option" data-value="5+">
                  <span>5ο ή μεγαλύτερο</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
              </div>
            </div>
          </div>

          <div class="field">
            <label for="experience">Εμπειρία στον προγραμματισμό</label>

            <select id="experience" name="experience" class="native-select-hidden" tabindex="-1" aria-hidden="true">
              <option value="" <?= (($_POST['experience'] ?? '') === '') ? 'selected' : '' ?>></option>
              <option value="beginner" <?= (($_POST['experience'] ?? '') === 'beginner') ? 'selected' : '' ?>>Αρχάριος</option>
              <option value="intermediate" <?= (($_POST['experience'] ?? '') === 'intermediate') ? 'selected' : '' ?>>Μέτριος</option>
              <option value="advanced" <?= (($_POST['experience'] ?? '') === 'advanced') ? 'selected' : '' ?>>Προχωρημένος</option>
            </select>

            <div class="glass-select" data-target="experience">
              <button type="button" class="glass-select-trigger" aria-expanded="false">
                <span class="glass-select-value is-placeholder">Επίλεξε...</span>
                <span class="glass-select-arrow"></span>
              </button>

              <div class="glass-select-menu" role="listbox">
                <button type="button" class="glass-select-option" data-value="beginner">
                  <span>Αρχάριος</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
                <button type="button" class="glass-select-option" data-value="intermediate">
                  <span>Μέτριος</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
                <button type="button" class="glass-select-option" data-value="advanced">
                  <span>Προχωρημένος</span>
                  <span class="glass-select-option-check">✓</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="consent-box">
          Με τη σύνδεσή σου δηλώνεις ότι έχεις ενημερωθεί για τον σκοπό της μελέτης και
          συναινείς στη συλλογή ανώνυμων δεδομένων αλληλεπίδρασης για ερευνητικούς σκοπούς.
        </div>
      </form>
    </section>

    <section class="hero">
      <div class="hero-inner">
        <div class="assistance-options">
          <div class="assistance-box">
            <div class="assistance-box-title">Video Assistance</div>
            <div class="assistance-box-text">
              Παρακολούθησε καθοδηγητικό video tutorial που εξηγεί την υλοποίηση μιας μονοτονικής στοίβας,
              χωρίς να δίνει πλήρη έτοιμο κώδικα.
            </div>
            <button type="submit" form="participantForm" name="assistance_type" value="video" class="btn">
              <span class="icon">→</span>
              Video Assistance
            </button>
          </div>

          <div class="assistance-box">
            <div class="assistance-box-title">AI Assistance</div>
            <div class="assistance-box-text">
              Χρησιμοποίησε AI assistance για επεξηγήσεις και συμβουλές πάνω στην υλοποίηση μιας μονοτονικής στοίβας,
              χωρίς παροχή πλήρους έτοιμου κώδικα.
            </div>
            <button type="submit" form="participantForm" name="assistance_type" value="ai" class="btn">
              <span class="icon">→</span>
              AI Assistance
            </button>
          </div>
        </div>

        <div class="form-footer">
          <span>Μετά την επιλογή assistance θα μεταφερθείς πρώτα στο pre ερωτηματολόγιο.</span>
        </div>
      </div>
    </section>
  </div>

  <div class="loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="loading-card">
      <img src="welcome.png" alt="Welcome" class="loading-image" />
      <h2 class="loading-title">Καλώς Ήρθες</h2>
      <p class="loading-text">Προετοιμάζουμε το pre ερωτηματολόγιο πριν ξεκινήσει το πείραμα.</p>
      <div class="loading-spinner" aria-hidden="true"></div>
    </div>
  </div>

  <script>
    (function initGlassSelects() {
      const glassSelects = document.querySelectorAll('.glass-select');

      function closeAll(except = null) {
        glassSelects.forEach((select) => {
          if (select === except) return;
          select.classList.remove('open');
          const trigger = select.querySelector('.glass-select-trigger');
          if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
      }

      function syncFromNative(glassSelect) {
        const targetId = glassSelect.dataset.target;
        const nativeSelect = document.getElementById(targetId);
        const valueEl = glassSelect.querySelector('.glass-select-value');
        const options = glassSelect.querySelectorAll('.glass-select-option');

        if (!nativeSelect || !valueEl) return;

        const currentValue = nativeSelect.value;

        if (currentValue === '') {
          valueEl.textContent = 'Επίλεξε...';
          valueEl.classList.add('is-placeholder');
        } else {
          const currentOption = nativeSelect.options[nativeSelect.selectedIndex];
          const currentLabel = currentOption ? currentOption.textContent.trim() : 'Επίλεξε...';
          valueEl.textContent = currentLabel;
          valueEl.classList.remove('is-placeholder');
        }

        options.forEach((optionBtn) => {
          optionBtn.classList.toggle('is-selected', optionBtn.dataset.value === currentValue);
        });
      }

      glassSelects.forEach((glassSelect) => {
        const targetId = glassSelect.dataset.target;
        const nativeSelect = document.getElementById(targetId);
        const trigger = glassSelect.querySelector('.glass-select-trigger');
        const options = glassSelect.querySelectorAll('.glass-select-option');

        if (!nativeSelect || !trigger) return;

        syncFromNative(glassSelect);

        trigger.addEventListener('click', () => {
          const willOpen = !glassSelect.classList.contains('open');
          closeAll(glassSelect);
          glassSelect.classList.toggle('open', willOpen);
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });

        options.forEach((optionBtn) => {
          optionBtn.addEventListener('click', () => {
            const selectedValue = optionBtn.dataset.value ?? '';
            nativeSelect.value = selectedValue;
            nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            syncFromNative(glassSelect);
            glassSelect.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.focus();
          });
        });

        nativeSelect.addEventListener('change', () => {
          syncFromNative(glassSelect);
        });
      });

      document.addEventListener('click', (event) => {
        const clickedInside = event.target.closest('.glass-select');
        if (!clickedInside) {
          closeAll();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeAll();
        }
      });
    })();
  </script>

  <?php if (!empty($redirectTarget) && empty($errorMsg)): ?>
    <script>
      (function () {
        const overlay = document.getElementById('loadingOverlay');
        document.body.classList.add('loading-active');
        overlay.classList.add('active');
        overlay.setAttribute('aria-hidden', 'false');

        setTimeout(function () {
          window.location.href = <?= json_encode($redirectTarget) ?>;
        }, 1200);
      })();
    </script>
  <?php endif; ?>
</body>
</html>