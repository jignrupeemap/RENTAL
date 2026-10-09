<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, string> route parameters, e.g. ['id' => '10001'] */
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $files,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST, $_FILES, $_SERVER);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** Trimmed string input; never returns arrays. */
    public function str(string $key, string $default = ''): string
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->body[$key] ?? $this->query[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function ip(): string
    {
        // Hostinger sits behind its own proxy layer; REMOTE_ADDR is the client
        // on shared hosting. Do not trust X-Forwarded-For blindly.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function contentLength(): int
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? 0);
    }

    /** Path of the Referer when it is this site, else null. */
    public function refererPath(): ?string
    {
        $ref = (string) ($this->server['HTTP_REFERER'] ?? '');
        $base = rtrim((string) Config::env('APP_URL', ''), '/');
        if ($ref === '' || $base === '' || !str_starts_with($ref, $base . '/')) {
            return null;
        }
        return '/' . ltrim((string) parse_url($ref, PHP_URL_PATH), '/');
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }
}
