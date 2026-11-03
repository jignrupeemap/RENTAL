<?php /** Favicons, theme colour and the brand font, shared by the site and admin layouts. */ ?>
    <?php /* Google Search shows a site icon only if one is 48px or a multiple of it, so the
             PNGs come first. Every icon URL carries ?v=<mtime>, so Google and browsers fetch
             a fresh copy whenever the file changes instead of keeping an old one. */ ?>
    <link rel="icon" href="<?= e(asset('img/icon-48.png')) ?>" sizes="48x48" type="image/png">
    <link rel="icon" href="<?= e(asset('img/icon-192.png')) ?>" sizes="192x192" type="image/png">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('favicon.ico') . '?v=' . (int) @filemtime(PUBLIC_ROOT . '/favicon.ico')) ?>" sizes="16x16 32x32 48x48 64x64">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <meta name="theme-color" content="#0f5c63">
    <link rel="preload" href="<?= e(asset('fonts/inter-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
