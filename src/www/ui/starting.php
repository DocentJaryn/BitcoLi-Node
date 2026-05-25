<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="10">
    <title>BitcoLi_v2 Node — Starting…</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0d1117; color: #c9d1d9;
            min-height: 100vh; display: flex;
            align-items: center; justify-content: center;
            padding: 2rem 1rem;
        }
        .card {
            background: #161b22; border: 1px solid #30363d;
            border-radius: 8px; padding: 2rem 2.5rem;
            max-width: 480px; width: 100%; text-align: center;
        }
        h1 { color: #f7931a; font-size: 1.5rem; margin-bottom: 1rem; }
        p  { color: #8b949e; font-size: 0.9rem; line-height: 1.6; margin-bottom: 0.75rem; }
        .spinner {
            width: 36px; height: 36px; margin: 1.25rem auto;
            border: 3px solid #30363d; border-top-color: #f7931a;
            border-radius: 50%; animation: spin 0.9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .hint { font-size: 0.78rem; color: #484f58; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>BitcoLi_v2 Node</h1>
        <div class="spinner"></div>
        <p>The node is starting up.<br>The database is not ready yet.</p>
        <p>This page will refresh automatically every 10 seconds.</p>
        <p class="hint">If this message persists for more than a few minutes,<br>
        check the database service and application logs.</p>
    </div>
</body>
</html>