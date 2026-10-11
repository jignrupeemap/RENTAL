<?php
declare(strict_types=1);

/**
 * Shared helpers for the HTTP end-to-end tests: a cookie-keeping Browser,
 * check(), and readers for the dev mail/SMS logs.
 */

use App\Core\Config;

$base = rtrim((string) Config::env('APP_URL'), '/');
$fail = 0;
$pass = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    echo ($ok ? "  PASS  " : "  FAIL  "), $label, (!$ok && $detail !== '' ? "  [{$detail}]" : ''), PHP_EOL;
    $ok ? $pass++ : $fail++;
}

/** Minimal browser: keeps cookies, does not follow redirects. */
final class Browser
{
    private string $jar;
    public string $body = '';
    public int $status = 0;
    public string $location = '';
    /** Raw response headers of the last request. */
    public string $headers = '';
    /** Empty = curl's default (none sent). Set a browser-like one to test view counting. */
    public string $userAgent = '';

    public function __construct(private readonly string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    /** POST with the CSRF token taken from the last page (unless $csrf === false). */
    public function post(string $path, array $data = [], bool $csrf = true): self
    {
        if ($csrf && !isset($data['_token'])) {
            $data['_token'] = $this->token();
        }
        return $this->request('POST', $path, $data);
    }

    /**
     * Multipart POST with files: $files = ['photos[]' => [path, ...]] or ['video' => path].
     * $ajax sends the headers fetch()/XHR send, so the app answers JSON.
     */
    public function upload(string $path, array $files, array $data = [], bool $ajax = false): self
    {
        $data['_token'] ??= $this->token();
        $n = 0;
        foreach ($files as $field => $paths) {
            foreach ((array) $paths as $file) {
                $key = str_ends_with($field, '[]') ? substr($field, 0, -2) . '[' . $n++ . ']' : $field;
                $data[$key] = new CURLFile($file, '', basename($file));
            }
        }
        return $this->request('POST', $path, $data, $ajax ? ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'] : [], true);
    }

    /** POST as fetch() does (X-Requested-With), so the app answers JSON. */
    public function ajax(string $path, array $data = []): self
    {
        $data['_token'] ??= $this->token();
        return $this->request('POST', $path, $data, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
    }

    public function json(): ?array
    {
        $j = json_decode($this->body, true);
        return is_array($j) ? $j : null;
    }

    private string $lastToken = '';

    /** CSRF token from the last HTML page (kept across JSON answers). */
    public function token(): string
    {
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $this->body, $m)) {
            $this->lastToken = $m[1];
        }
        return $this->lastToken;
    }

    /** Follow a redirect once. */
    public function follow(): self
    {
        $loc = $this->location;
        return $this->get(str_starts_with($loc, $this->base) ? substr($loc, strlen($this->base)) : $loc);
    }

    public function has(string $text): bool
    {
        return str_contains(html_entity_decode($this->body, ENT_QUOTES), $text);
    }

    private function request(string $method, string $path, array $data = [], array $headers = [], bool $multipart = false): self
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
        }
        if ($this->userAgent !== '') {
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        curl_close($ch);
        $this->headers = substr($raw, 0, $headerSize);
        $this->body = substr($raw, $headerSize);
        return $this;
    }
}

function redirectsTo(Browser $b, string $path): bool
{
    global $base;
    return $b->status === 302 && $b->location === $base . $path;
}

/** Read a dev log file. The shared project folder can fail a read now and then, so retry. */
function readLog(string $file): string
{
    for ($i = 0; $i < 5; $i++) {
        $data = @file_get_contents($file);
        if ($data !== false) {
            return $data;
        }
        usleep(200000);
    }
    return '';
}

function lastSmsCode(string $mobile): ?string
{
    $file = APP_ROOT . '/storage/logs/sms-' . gmdate('Y-m-d') . '.log';
    if (!is_file($file)) {
        return null;
    }
    preg_match_all('/OTP for ' . $mobile . ': (\d{6})/', readLog($file), $m);
    return $m[1] ? end($m[1]) : null;
}

function lastMailLink(string $to, string $path): ?string
{
    $file = APP_ROOT . '/storage/logs/mail-' . gmdate('Y-m-d') . '.log';
    if (!is_file($file)) {
        return null;
    }
    $log = readLog($file);
    $pos = strrpos($log, 'To: ' . $to);
    if ($pos === false) {
        return null;
    }
    preg_match('#https?://[^\s]+' . preg_quote($path, '#') . '\?token=([a-f0-9]{64})#', substr($log, $pos), $m);
    return $m[1] ?? null;
}

/** Write a test JPEG/PNG/WebP of the given size (gradient so it is a real photo-like image). */
function testImage(string $path, int $w, int $h, string $format = 'jpeg'): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x += 40) {
        imagefilledrectangle($img, $x, 0, $x + 39, $h, imagecolorallocate($img, ($x * 7) % 255, 120, 200 - ($x % 200)));
    }
    match ($format) {
        'png'  => imagepng($img, $path),
        'webp' => imagewebp($img, $path, 80),
        default => imagejpeg($img, $path, 85),
    };
    imagedestroy($img);
    return $path;
}
