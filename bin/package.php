<?php
declare(strict_types=1);

/**
 * Builds the Hostinger release zip (run on your computer, not the server):
 *   php bin/package.php                 → dist/rental-site-hostinger-YYYYMMDD.zip
 *   php bin/package.php --out=/path     → write the zip into another folder
 *
 * The zip has the two folders Hostinger needs, side by side:
 *   public_html/   contents of public/ (upload into your domain's public_html)
 *   rental-app/    everything else     (upload next to public_html)
 *
 * Left out on purpose: config/env.php (your secrets), tests/, the demo-data
 * seeder, logs, sessions, cache, backups and every uploaded photo/video, so
 * an update can be unpacked over a live site without touching its data.
 * Minified CSS/JS and database/install.sql are rebuilt first.
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$root = dirname(__DIR__);
$opts = getopt('', ['out::']);
$outDir = rtrim((string) ($opts['out'] ?? $root . '/dist'), '/');
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP's zip extension is needed to build the package.\n");
    exit(1);
}

foreach (['build-assets.php', 'build-install-sql.php'] as $step) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $step), $code);
    if ($code !== 0) {
        fwrite(STDERR, "{$step} failed.\n");
        exit(1);
    }
}

/** Paths (relative to the project root) never shipped. */
$exclude = [
    '#^config/env\.php$#',
    '#^tests(/|$)#',
    '#^dist(/|$)#',
    '#^backups(/|$)#',
    '#^bin/seed-demo\.php$#',          // development only (spec 66)
    '#^storage/(logs|sessions|cache|tmp)/(?!\.gitkeep$)#',
    '#^public/uploads/[^/]+/(?!\.gitkeep$)#', // uploaded media stays on the server
    '#(^|/)\.git(/|$)#',
    '#(^|/)\.DS_Store$#',
    '#(^|/)node_modules(/|$)#',
];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($root) + 1);
    foreach ($exclude as $re) {
        if (preg_match($re, $rel)) {
            continue 2;
        }
    }
    $target = str_starts_with($rel, 'public/') ? 'public_html/' . substr($rel, 7) : 'rental-app/' . $rel;
    $files[$target] = $f->getPathname();
}
ksort($files);

if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}
$zipPath = $outDir . '/rental-site-hostinger-' . gmdate('Ymd') . '.zip';
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create {$zipPath}\n");
    exit(1);
}
$dirs = [];
foreach ($files as $target => $source) {
    for ($d = dirname($target); $d !== '.' && !isset($dirs[$d]); $d = dirname($d)) {
        $dirs[$d] = true;
    }
    $zip->addFile($source, $target);
    $zip->setExternalAttributesName($target, ZipArchive::OPSYS_UNIX, (0100644 & 0xFFFF) << 16);
}
foreach (array_keys($dirs) as $d) {
    $zip->addEmptyDir($d);
    $zip->setExternalAttributesName($d . '/', ZipArchive::OPSYS_UNIX, (040755 & 0xFFFF) << 16);
}
$zip->close();
printf("%s: %d files, %s KB\n", $zipPath, count($files), number_format(filesize($zipPath) / 1024));
