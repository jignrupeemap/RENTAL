<?php /** @var string $title */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title ?? 'Page not found') ?></title>
    <style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#f6f7f9;color:#1d2433}main{text-align:center;padding:24px}a{color:#0b5bd3}</style>
</head>
<body>
<main>
    <h1><?= e($title ?? 'Page not found') ?></h1>
    <p>The page you are looking for does not exist or has moved.</p>
    <p><a href="<?= e(url('/')) ?>">Go to homepage</a></p>
</main>
</body>
</html>
