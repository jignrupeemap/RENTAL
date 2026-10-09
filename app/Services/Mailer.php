<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Services\Mail\SmtpTransport;

/**
 * Every email goes through email_queue (so nothing is lost if SMTP is down).
 * Urgent mail (password reset, verification) is also attempted immediately;
 * bin/cron.php retries anything still queued.
 *
 * MAIL_DRIVER in config/env.php: smtp (production) | log (development: the
 * message is written to storage/logs/mail-YYYY-MM-DD.log, nothing is sent).
 */
final class Mailer
{
    /** Render views/emails/{template}.php and queue it. */
    public static function sendTemplate(string $to, string $toName, string $subject, string $template, array $data, bool $immediate = false): void
    {
        $html = View::render('emails/' . $template, [...$data, 'subject' => $subject]);
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/h\d>/i', "\n", $html) ?? ''), ENT_QUOTES, 'UTF-8'));
        $text = preg_replace("/\n\s*\n+/", "\n\n", $text) ?? $text;
        self::queue($to, $toName, $subject, $html, $text, $immediate);
    }

    public static function queue(string $to, string $toName, string $subject, string $html, string $text, bool $immediate = false): void
    {
        $id = Database::insert(
            'INSERT INTO email_queue (to_email, to_name, subject, body_html, body_text) VALUES (?, ?, ?, ?, ?)',
            [$to, $toName, $subject, $html, $text]
        );
        if ($immediate) {
            self::deliver($id);
        }
    }

    /** Send queued mail. Called by cron; returns number sent. */
    public static function processQueue(int $batch = 20): int
    {
        $ids = array_column(Database::all(
            "SELECT id FROM email_queue WHERE status = 'queued' AND send_after <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, $batch)
        ), 'id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += self::deliver((int) $id) ? 1 : 0;
        }
        return $sent;
    }

    private static function deliver(int $id): bool
    {
        // Claim the row so cron and a web request never send it twice.
        $claimed = Database::run(
            "UPDATE email_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'queued'",
            [$id]
        )->rowCount();
        if ($claimed !== 1) {
            return false;
        }
        $mail = Database::one('SELECT * FROM email_queue WHERE id = ?', [$id]);

        try {
            $driver = (string) Config::env('MAIL_DRIVER', 'log');
            if ($driver === 'smtp') {
                (new SmtpTransport(
                    (string) Config::env('MAIL_HOST'),
                    (int) Config::env('MAIL_PORT', 465),
                    (string) Config::env('MAIL_ENCRYPTION', 'ssl'),
                    (string) Config::env('MAIL_USERNAME'),
                    (string) Config::env('MAIL_PASSWORD'),
                ))->send(
                    (string) Config::env('MAIL_FROM'),
                    (string) Config::env('MAIL_FROM_NAME', ''),
                    $mail['to_email'],
                    (string) $mail['to_name'],
                    $mail['subject'],
                    $mail['body_html'],
                    (string) $mail['body_text']
                );
            } elseif (Config::isProduction()) {
                // The log driver writes reset links in plain text: development only.
                throw new \RuntimeException('MAIL_DRIVER must be "smtp" in production.');
            } else {
                @file_put_contents(
                    APP_ROOT . '/storage/logs/mail-' . gmdate('Y-m-d') . '.log',
                    sprintf("=== %s UTC | To: %s | Subject: %s\n%s\n\n", gmdate('Y-m-d H:i:s'), $mail['to_email'], $mail['subject'], $mail['body_text']),
                    FILE_APPEND | LOCK_EX
                );
            }
            Database::run("UPDATE email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?", [$id]);
            return true;
        } catch (\Throwable $e) {
            $final = (int) $mail['attempts'] >= 5;
            Database::run(
                "UPDATE email_queue SET status = ?, last_error = ?, send_after = UTC_TIMESTAMP() + INTERVAL ? MINUTE WHERE id = ?",
                [$final ? 'failed' : 'queued', mb_substr($e->getMessage(), 0, 500), 5 * (int) $mail['attempts'], $id]
            );
            Logger::warning('Email delivery failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
