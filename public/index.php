<?php
declare(strict_types=1);

/**
 * Front controller: every request that is not a real file under public/
 * is rewritten here by .htaccess.
 *
 * On Hostinger the contents of public/ are uploaded to public_html and the
 * rest of the application to a sibling folder (default: ../rental-app).
 * Locally the application is simply the parent directory.
 */

// PHP's built-in dev server: let it serve real files (css/js/uploads) itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

$candidates = [
    dirname(__DIR__),               // local / everything-in-one-folder layout
    dirname(__DIR__) . '/rental-app', // Hostinger: public_html + ../rental-app
];
$appRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $appRoot = $candidate;
        break;
    }
}
if ($appRoot === null) {
    http_response_code(500);
    exit('Application files not found. See docs/DEPLOYMENT.md.');
}

define('PUBLIC_ROOT', __DIR__);
require $appRoot . '/app/bootstrap.php';

use App\Core\Request;
use App\Core\Router;
use App\Core\Security;
use App\Core\Session;
use App\Middleware\VerifyCsrf;

$request = Request::fromGlobals();
Security::sendHeaders($request);
Session::configure($request);

$router = new Router();
$router->globalMiddleware([VerifyCsrf::class]);
require APP_ROOT . '/routes/web.php';

$response = $router->dispatch($request);
if (App\Core\Config::env('APP_DEBUG') && !App\Core\Config::isProduction()) {
    // Development aid: query count and time per page (browser dev tools → Network → Timing).
    $response->headers['Server-Timing'] = sprintf('db;desc="%d queries";dur=%.1f, app;dur=%.1f',
        App\Core\Database::$queries, App\Core\Database::$queryTime, (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);
}
$response->send();
