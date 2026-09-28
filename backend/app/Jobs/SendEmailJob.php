<?php

namespace App\Jobs;

/**
 * Queued mail via the configured provider (Config\Email / SMTP). In dev the
 * message is written to the log instead of being sent.
 */
class SendEmailJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $cfg = config('UpdateCenter');
        if (! env('email.SMTPHost') && ! $cfg->isProduction()) {
            log_message('info', "[mail] to={to} subject={subject}\n{body}", $payload);

            return;
        }
        $email = service('email');
        $email->setFrom($cfg->mailFrom, 'WorkSuite Update Center');
        $email->setTo($payload['to']);
        $email->setSubject($payload['subject']);
        $email->setMessage($payload['body']);
        if (! $email->send(false)) {
            throw new \RuntimeException('Mail send failed: ' . $email->printDebugger(['headers']));
        }
    }
}
