<?php /** Favicons, theme colour and the brand font, shared by the site and admin layouts. */ ?>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(url('favicon.ico')) ?>" sizes="48x48">
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <meta name="theme-color" content="#0f5c63">
    <link rel="preload" href="<?= e(asset('fonts/outfit-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
