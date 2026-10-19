<?php

declare(strict_types=1);

namespace OpenSim\Services;

/**
 * Sends mail with the mail function of PHP: it needs a mail system on the machine (a local SMTP server, sendmail).
 */
final class PhpMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $html, string $from): bool
    {
        $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";

        return mail($to, $subject, $html, $headers);
    }
}
