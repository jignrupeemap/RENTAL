<?php
declare(strict_types=1);

/**
 * Writes minified copies of our own CSS/JS next to the originals
 * (app.css → app.min.css, app.js → app.min.js ...). In production asset()
 * serves the .min copy when it is at least as new as the original, so a
 * forgotten rebuild falls back to the readable file instead of stale code.
 * Run after changing CSS/JS:   php bin/build-assets.php
 * No Node.js needed. Bootstrap ships already minified and is skipped.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

function minify_css(string $css): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;          // comments
    $css = preg_replace('/\s+/', ' ', $css) ?? $css;                 // collapse whitespace
    $css = preg_replace('/\s*([{};,>])\s*/', '$1', $css) ?? $css;    // around punctuation (not + or -, calc() needs them)
    $css = preg_replace('/:\s+/', ':', $css) ?? $css;
    return trim(str_replace(';}', '}', $css)) . "\n";
}

/** Conservative: drops whole-line comments, indentation and blank lines; never touches code inside a line. */
function minify_js(string $js): string
{
    $out = [];
    $inBlock = false;
    foreach (preg_split('/\R/', $js) ?: [] as $line) {
        $t = trim($line);
        if ($inBlock) {
            $inBlock = !str_contains($t, '*/');
            continue;
        }
        if (str_starts_with($t, '/*')) {
            $inBlock = !str_contains($t, '*/');
            continue;
        }
        if ($t === '' || str_starts_with($t, '//')) {
            continue;
        }
        $out[] = $t;
    }
    return implode("\n", $out) . "\n";
}

$root = PUBLIC_ROOT . '/assets';
$files = array_merge(glob($root . '/css/*.css') ?: [], glob($root . '/js/*.js') ?: []);
foreach ($files as $src) {
    if (str_contains(basename($src), '.min.')) {
        continue;
    }
    $ext = pathinfo($src, PATHINFO_EXTENSION);
    $dest = substr($src, 0, -strlen($ext) - 1) . '.min.' . $ext;
    $code = (string) file_get_contents($src);
    $min = $ext === 'css' ? minify_css($code) : minify_js($code);
    file_put_contents($dest, $min, LOCK_EX);
    touch($dest, max(filemtime($src), time()));
    printf("%-28s %6.1f KB → %6.1f KB\n", basename($dest), strlen($code) / 1024, strlen($min) / 1024);
}
