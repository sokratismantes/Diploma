<?php
session_start();
$showSignupSuccess = !empty($_SESSION['signup_success']);
unset($_SESSION['signup_success']); // να μην ξαναεμφανιστεί στο refresh
?>

<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Welcome – Programming Tasks Environment</title>
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
      --border: rgba(148,163,184,0.14);
      --shadow-sm: 0 10px 30px rgba(0,0,0,0.16);
      --shadow-md: 0 20px 48px rgba(0,0,0,0.24);
      --shadow-lg: 0 26px 70px rgba(0,0,0,0.30);
      --radius-md: 18px;
      --radius-lg: 24px;
      --transition-fast: 180ms ease;
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
    }

    body.loading-active .shell {
      filter: blur(10px);
      transition: filter 0.35s ease;
      pointer-events: none;
      user-select: none;
    }

    .shell {
      width: 100%;
      max-width: 760px;
      display: flex;
      justify-content: center;
      animation: pageEntrance 0.8s cubic-bezier(0.22, 1, 0.36, 1);
      transform-origin: center;
    }

    .hero {
      width: 100%;
      background: var(--surface);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-md);
      border-radius: var(--radius-lg);
      overflow: hidden;
      padding: 1.5rem;
      background:
        radial-gradient(circle at top left, rgba(91,140,255,0.14), transparent 34%),
        var(--surface-2);
    }

    .hero-inner {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1.2rem;
      text-align: center;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 0.9rem;
      padding: 0;
      border: none;
      background: transparent;
      box-shadow: none;
      animation: fadeUp 0.7s ease-out 0.08s both;
    }

    .brand-icon {
      width: 52px;
      height: 52px;
      border-radius: 16px;
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 14px 30px rgba(59,130,246,0.28);
      font-size: 1.35rem;
      font-weight: 800;
      color: #fff;
      flex: 0 0 auto;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
      gap: 0.12rem;
      text-align: left;
    }

    .brand-text span:first-child {
      font-size: 0.76rem;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: var(--text-muted);
    }

    .brand-text span:last-child {
      font-weight: 700;
      font-size: 1.05rem;
      letter-spacing: -0.02em;
      color: var(--text);
    }

    .hero-title {
      margin: 0;
      font-size: clamp(1.4rem, 2.4vw, 1.8rem);
      font-weight: 800;
      line-height: 1.2;
      letter-spacing: -0.03em;
      max-width: none;
      white-space: nowrap;
      animation: fadeUp 0.7s ease-out 0.16s both;
    }

    .hero-title .accent {
      color: #dbeafe;
    }

    .hero-image {
      display: block;
      margin: 0.2rem auto 0.4rem;
      max-width: 100%;
      width: min(360px, 75%);
      height: auto;
      object-fit: contain;
      filter: drop-shadow(0 16px 30px rgba(59,130,246,0.18));
      animation: fadeUp 0.8s ease-out 0.24s both;
    }

    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 0.7rem;
      margin-top: 0.2rem;
      animation: fadeUp 0.75s ease-out 0.32s both;
    }

    .btn {
      border-radius: 12px;
      border: 1px solid transparent;
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      color: #fff;
      min-height: 46px;
      padding: 0.75rem 1.2rem;
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

    .btn.secondary {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(148,163,184,0.18);
      color: var(--text-soft);
      box-shadow: var(--shadow-sm);
    }

    .btn.secondary:hover {
      background: rgba(255,255,255,0.05);
      box-shadow: var(--shadow-md);
    }

    .footer-note {
      font-size: 0.78rem;
      color: var(--text-muted);
      line-height: 1.5;
      margin-top: 0.2rem;
    }

    /* ===== SIGNUP SUCCESS POPUP ===== */
    .signup-overlay {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: rgba(3, 8, 20, 0.52);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.2rem;
      transition: opacity 0.3s ease-out, visibility 0.3s ease-out;
      opacity: 1;
      visibility: visible;
    }

    .signup-overlay.hidden {
      opacity: 0;
      visibility: hidden;
    }

    .signup-modal {
      width: min(100%, 430px);
      background:
        radial-gradient(circle at top left, rgba(91,140,255,0.14), transparent 34%),
        rgba(13, 20, 38, 0.94);
      border-radius: var(--radius-lg);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-lg);
      padding: 1.4rem 1.5rem 1.25rem;
      text-align: left;
      animation: modalPop 0.55s cubic-bezier(0.22, 1, 0.36, 1);
      transform-origin: center;
    }

    .signup-modal-header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 0.7rem;
    }

    .signup-modal-icon {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 12px 26px rgba(34,197,94,0.24);
      color: #fff;
      font-size: 1.05rem;
      font-weight: 800;
      flex: 0 0 auto;
    }

    .signup-modal-title {
      font-size: 1rem;
      font-weight: 700;
      color: var(--text);
      letter-spacing: -0.01em;
    }

    .signup-modal-text {
      color: var(--text-soft);
      margin-bottom: 1rem;
      font-size: 0.88rem;
      line-height: 1.6;
    }

    .signup-modal-btn {
      border-radius: 12px;
      border: 1px solid transparent;
      background: linear-gradient(180deg, #5b8cff 0%, #3b82f6 100%);
      color: #fff;
      min-height: 44px;
      padding: 0.7rem 1.1rem;
      font-size: 0.84rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
      cursor: pointer;
      text-decoration: none;
      box-shadow: 0 10px 26px rgba(59,130,246,0.28);
      transition: transform var(--transition-fast), box-shadow var(--transition-fast);
    }

    .signup-modal-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 34px rgba(59,130,246,0.36);
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
      z-index: 99999;
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
      animation: modalPop 0.45s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .loading-image {
      width: 200px;
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

    @keyframes pageEntrance {
      0% {
        opacity: 0;
        transform: translateY(18px) scale(0.985);
      }
      100% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @keyframes fadeUp {
      0% {
        opacity: 0;
        transform: translateY(14px);
      }
      100% {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes modalPop {
      0% {
        opacity: 0;
        transform: translateY(16px) scale(0.94);
      }
      100% {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @media (max-width: 640px) {
      .hero {
        padding: 1.2rem;
      }

      .brand {
        flex-direction: column;
        text-align: center;
      }

      .brand-text {
        text-align: center;
      }

      .hero-actions {
        flex-direction: column;
      }

      .btn {
        width: 100%;
      }

      .loading-card {
        padding: 1.6rem 1.2rem;
      }

      .loading-image {
        width: 140px;
      }
    }
  </style>
</head>
<body>

  <div class="shell">
    <section class="hero">
      <div class="hero-inner">
        <div class="brand">
          <div class="brand-icon">λ</div>
          <div class="brand-text">
            <span>PROGRAMMING STUDY</span>
            <span>Interactive Tasks Environment</span>
          </div>
        </div>

        <h1 class="hero-title">
          Καλώς ήρθες στο Πειραματικό
          <span class="accent">Περιβάλλον!</span>
        </h1>

        <img
          src="welcome.png"
          alt="Welcome"
          class="hero-image"
        >

        <div class="hero-actions">
          <a href="log.php" class="btn" id="continueBtn">
            <span class="icon">→</span>
            Continue
          </a>
        </div>
      </div>
    </section>
  </div>

  <div class="loading-overlay" id="loadingOverlay" aria-hidden="true">
    <div class="loading-card">
      <img src="welcome.png" alt="Welcome" class="loading-image" />
      <h2 class="loading-title">Καλώς Ήρθες</h2>
      <p class="loading-text">Προετοιμάζουμε το περιβάλλον σου. Θα μεταφερθείς αμέσως στη σωστή σελίδα.</p>
      <div class="loading-spinner" aria-hidden="true"></div>
    </div>
  </div>

  <script>
    (function () {
      const overlay = document.getElementById('signup-success-overlay');
      const closeBtn = document.getElementById('signup-success-close');

      if (overlay && closeBtn) {
        function hideOverlay() {
          overlay.classList.add('hidden');
        }

        closeBtn.addEventListener('click', hideOverlay);
        setTimeout(hideOverlay, 5000);
      }
    })();

    (function () {
      const continueBtn = document.getElementById('continueBtn');
      const loadingOverlay = document.getElementById('loadingOverlay');

      if (!continueBtn || !loadingOverlay) return;

      continueBtn.addEventListener('click', function (e) {
        e.preventDefault();
        document.body.classList.add('loading-active');
        loadingOverlay.classList.add('active');
        loadingOverlay.setAttribute('aria-hidden', 'false');

        setTimeout(function () {
          window.location.href = 'log.php';
        }, 4000);
      });
    })();

    window.addEventListener('pageshow', function () {
      document.body.classList.remove('loading-active');

      const loadingOverlay = document.getElementById('loadingOverlay');
      if (loadingOverlay) {
        loadingOverlay.classList.remove('active');
        loadingOverlay.setAttribute('aria-hidden', 'true');
      }
    });
  </script>
</body>
</html>