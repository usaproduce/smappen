<?php
namespace App\Services;

use App\Core\Config;

/**
 * Transactional mail. Three drivers selected via MAIL_DRIVER:
 *   - "postmark" (default if POSTMARK_TOKEN set): POST to https://api.postmarkapp.com/email
 *   - "resend"  (if RESEND_API_KEY set):         POST to https://api.resend.com/emails
 *   - "log"     (default in dev):                writes the payload to storage/logs/mail.log
 *
 * Why no PHPMailer SMTP: SMTP from a residential droplet IP lands in spam.
 * Transactional providers handle DKIM/SPF/feedback loops so deliverability
 * is a setup-once concern, not a per-message one.
 */
class MailService
{
    private string $driver;

    public function __construct()
    {
        $this->driver = strtolower((string) Config::get('MAIL_DRIVER', ''));
        if ($this->driver === '') {
            if (Config::get('POSTMARK_TOKEN')) $this->driver = 'postmark';
            elseif (Config::get('RESEND_API_KEY')) $this->driver = 'resend';
            else $this->driver = 'log';
        }
    }

    public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
    {
        $from = Config::get('MAIL_FROM', 'no-reply@smappen.mygreendock.com');
        $fromName = Config::get('MAIL_FROM_NAME', 'Smappen');
        $textBody ??= strip_tags($htmlBody);

        return match ($this->driver) {
            'postmark' => $this->sendPostmark($from, $fromName, $to, $subject, $htmlBody, $textBody),
            'resend'   => $this->sendResend($from, $fromName, $to, $subject, $htmlBody, $textBody),
            default    => $this->sendLog($from, $fromName, $to, $subject, $htmlBody, $textBody),
        };
    }

    private function sendPostmark(string $from, string $fromName, string $to, string $subject, string $html, string $text): bool
    {
        $token = Config::get('POSTMARK_TOKEN', '');
        if ($token === '') return $this->sendLog($from, $fromName, $to, $subject, $html, $text);
        $resp = $this->postJson('https://api.postmarkapp.com/email', [
            'From' => "$fromName <$from>",
            'To' => $to,
            'Subject' => $subject,
            'HtmlBody' => $html,
            'TextBody' => $text,
            'MessageStream' => Config::get('POSTMARK_STREAM', 'outbound'),
        ], [
            'Accept: application/json',
            'X-Postmark-Server-Token: ' . $token,
        ]);
        return $resp['code'] >= 200 && $resp['code'] < 300;
    }

    private function sendResend(string $from, string $fromName, string $to, string $subject, string $html, string $text): bool
    {
        $key = Config::get('RESEND_API_KEY', '');
        if ($key === '') return $this->sendLog($from, $fromName, $to, $subject, $html, $text);
        $resp = $this->postJson('https://api.resend.com/emails', [
            'from' => "$fromName <$from>",
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ], [
            'Authorization: Bearer ' . $key,
        ]);
        return $resp['code'] >= 200 && $resp['code'] < 300;
    }

    /** Append to a log so dev can inspect what would have been sent. */
    private function sendLog(string $from, string $fromName, string $to, string $subject, string $html, string $text): bool
    {
        $base = dirname(__DIR__, 2);
        $logDir = $base . '/storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        $msg = sprintf(
            "[%s] %s → %s\nSubject: %s\n--- TEXT ---\n%s\n--- HTML ---\n%s\n\n=========================================\n\n",
            date('c'), "$fromName <$from>", $to, $subject, $text, $html
        );
        file_put_contents($logDir . '/mail.log', $msg, FILE_APPEND);
        return true;
    }

    private function postJson(string $url, array $body, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json; charset=utf-8',
            ], $extraHeaders),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            error_log('MailService HTTP error: ' . $err);
            return ['code' => 0, 'body' => ''];
        }
        return ['code' => $code, 'body' => $resp];
    }
}
