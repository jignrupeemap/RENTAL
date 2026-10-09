<?php
declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Minimal SMTP client (AUTH LOGIN, SSL on 465 or STARTTLS on 587).
 * Enough for Hostinger mailboxes; no external library needed.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // ssl | tls | none
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 15,
    ) {
    }

    public function send(string $from, string $fromName, string $to, string $toName, string $subject, string $html, string $text): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect(220);
            $ehloHost = parse_url((string) \App\Core\Config::env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost';
            $this->command("EHLO {$ehloHost}", 250);
            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS failed');
                }
                $this->command("EHLO {$ehloHost}", 250);
            }
            if ($this->username !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode($this->password), 235, true);
            }
            $this->command('MAIL FROM:<' . $this->clean($from) . '>', 250);
            $this->command('RCPT TO:<' . $this->clean($to) . '>', [250, 251]);
            $this->command('DATA', 354);
            $this->write($this->buildMessage($from, $fromName, $to, $toName, $subject, $html, $text) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function buildMessage(string $from, string $fromName, string $to, string $toName, string $subject, string $html, string $text): string
    {
        $boundary = 'b' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->address($from, $fromName),
            'To: ' . $this->address($to, $toName),
            'Subject: ' . mb_encode_mimeheader($this->clean($subject), 'UTF-8', 'B', "\r\n"),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--";
        // Base64 bodies never start a line with "." so no dot-stuffing is needed.
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function address(string $email, string $name): string
    {
        $email = $this->clean($email);
        return $name === '' ? "<{$email}>" : mb_encode_mimeheader($this->clean($name), 'UTF-8', 'B') . " <{$email}>";
    }

    /** Strip CR/LF to prevent header injection. */
    private function clean(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function command(string $line, int|array $expect, bool $secret = false): void
    {
        $this->write($line);
        $this->expect($expect, $secret ? '[credentials]' : $line);
    }

    private function write(string $data): void
    {
        fwrite($this->socket, $data . "\r\n");
    }

    private function expect(int|array $codes, string $after = 'connect'): void
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $codes, true)) {
            throw new \RuntimeException('SMTP error after ' . explode(' ', $after)[0] . ': ' . trim($response));
        }
    }
}
