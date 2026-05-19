<?php
session_start();
?>
<!DOCTYPE html>
<html lang="el">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Programming Tasks Environment</title>
  <style>
    * {
      box-sizing: border-box;
    }

    html, body {
      width: 100%;
      height: 100%;
      margin: 0;
      overflow: hidden;
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, #07101f 0%, #050b16 100%);
      font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    :fullscreen {
      background:
        radial-gradient(circle at 12% 18%, rgba(59,130,246,0.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(139,92,246,0.10), transparent 24%),
        linear-gradient(180deg, #07101f 0%, #050b16 100%);
    }

    .app-shell {
      position: relative;
      width: 100vw;
      height: 100vh;
      overflow: hidden;
    }

    .app-frame {
      width: 100vw;
      height: 100vh;
      border: 0;
      display: block;
      background: transparent;
    }

    .fullscreen-btn {
      position: fixed;
      top: 18px;
      right: 18px;
      z-index: 9999;
      border-radius: 12px;
      border: 1px solid rgba(148,163,184,0.24);
      background: rgba(13, 20, 38, 0.78);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      color: #e8eefb;
      height: 42px;
      padding: 0 1rem;
      font-size: 0.84rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      cursor: pointer;
      box-shadow: 0 16px 34px rgba(0,0,0,0.24);
      transition: transform 180ms ease, box-shadow 180ms ease, background 180ms ease, border-color 180ms ease;
      white-space: nowrap;
    }

    .fullscreen-btn:hover {
      transform: translateY(-1px);
      background: rgba(13, 20, 38, 0.88);
      border-color: rgba(91,140,255,0.32);
      box-shadow: 0 20px 40px rgba(0,0,0,0.28);
    }

    .fullscreen-btn:active {
      transform: translateY(0);
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <button id="fullscreen-btn" class="fullscreen-btn" type="button">
      <span>⛶</span>
      <span>Full screen</span>
    </button>

    <iframe
      class="app-frame"
      src="welcome.php"
      name="appFrame"
      allowfullscreen
    ></iframe>
  </div>

  <script>
    const fullscreenBtn = document.getElementById('fullscreen-btn');
    let fullscreenRequestedOnce = false;

    async function toggleFullscreen() {
      try {
        if (!document.fullscreenElement) {
          await document.documentElement.requestFullscreen();

          if (!fullscreenRequestedOnce) {
            fullscreenRequestedOnce = true;
            fullscreenBtn.style.display = 'none';
          }
        }
      } catch (err) {
        console.error('Fullscreen error:', err);
      }
    }

    fullscreenBtn.addEventListener('click', toggleFullscreen);
  </script>
</body>
</html>