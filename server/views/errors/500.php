<?php /** @var string $ref set by ErrorHandler */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Something went wrong</title>
    <style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#f6f7f9;color:#1d2433}main{text-align:center;padding:24px}small{color:#8a93a3}</style>
</head>
<body>
<main>
    <h1>Something went wrong. Please try again.</h1>
    <p><small>Reference: <?= htmlspecialchars((string) ($ref ?? ''), ENT_QUOTES, 'UTF-8') ?></small></p>
</main>
</body>
</html>
