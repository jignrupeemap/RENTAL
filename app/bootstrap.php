<?php
declare(strict_types=1);

/**
 * Shared bootstrap for web (public/index.php) and CLI (bin/*.php).
 * Loads config, autoloader, error handling and timezone. Nothing here
 * outputs HTML.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_START', microtime(true));

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('This application requires PHP 8.2 or newer.');
}

// PSR-4 style autoloader: App\Core\Router => app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/app/Helpers/functions.php';

use App\Core\Config;
use App\Core\ErrorHandler;

Config::load(APP_ROOT . '/config');

// Web root (public/ locally, public_html on Hostinger). CLI scripts and cron
// use PUBLIC_PATH from env.php when set; otherwise public/ inside the app,
// or the sibling public_html of the Hostinger layout (rental-app + public_html).
if (!defined('PUBLIC_ROOT')) {
    $publicRoot = (string) Config::env('PUBLIC_PATH');
    if ($publicRoot === '') {
        $publicRoot = !is_dir(APP_ROOT . '/public') && is_file(dirname(APP_ROOT) . '/public_html/index.php')
            ? dirname(APP_ROOT) . '/public_html'
            : APP_ROOT . '/public';
    }
    define('PUBLIC_ROOT', rtrim($publicRoot, '/'));
    unset($publicRoot);
}

// All internal dates are UTC; views convert with format_ist().
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Raw errors are never shown in production, even if APP_DEBUG was left on.
ErrorHandler::register((bool) Config::env('APP_DEBUG', false) && !Config::isProduction());
