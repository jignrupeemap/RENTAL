<?php
declare(strict_types=1);

namespace App\Core;

final class Security
{
    /**
     * Baseline security headers for every response. The CSP allows Google
     * Maps + Places (posting form map, Phase 4) and our own assets only; no inline scripts
     * other than those carrying the per-request nonce.
     */
    public static function sendHeaders(Request $request): void
    {
        if (headers_sent()) {
            return;
        }
        $nonce = self::nonce();
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}' https://maps.googleapis.com https://maps.gstatic.com; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            . "img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com https://i.ytimg.com; "
            . "frame-src https://*.google.com https://www.youtube-nocookie.com; "
            . "connect-src 'self' https://*.googleapis.com https://*.google.com https://*.gstatic.com data: blob:; "
            . "worker-src blob:; "
            . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
        );
        if ($request->isSecure()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** Per-request nonce for the few inline <script> tags we allow. */
    public static function nonce(): string
    {
        static $nonce = null;
        return $nonce ??= base64_encode(random_bytes(16));
    }

    /** Cryptographically secure token (hex). Store only its sha256 in the DB. */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
