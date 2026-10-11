<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Converts every PHP warning into an exception, logs it with a reference id,
 * and shows visitors a friendly page. Raw errors are only displayed when
 * APP_DEBUG is true (development).
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);
    }

    public static function handle(\Throwable $e): void
    {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        Logger::error($e->getMessage(), [
            'ref'  => $ref,
            'type' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
            'url'  => strtok((string) ($_SERVER['REQUEST_URI'] ?? 'cli'), '?'), // no query string: it can carry tokens
            'trace' => self::$debug ? $e->getTraceAsString() : substr($e->getTraceAsString(), 0, 2000),
        ]);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, ($e::class) . ': ' . $e->getMessage() . "\n" . (self::$debug ? $e->getTraceAsString() . "\n" : ''));
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (self::$debug) {
            echo '<pre style="white-space:pre-wrap">' . e($e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()) . '</pre>';
            return;
        }

        $view = APP_ROOT . '/views/errors/500.php';
        if (is_file($view)) {
            require $view;
        } else {
            echo 'Something went wrong. Please try again. (Ref ' . $ref . ')';
        }
    }
}
