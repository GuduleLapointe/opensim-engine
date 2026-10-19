<?php

declare(strict_types=1);

namespace OpenSim\Services;

/**
 * Sends an email: the instant messages of an avatar who is not there, forwarded to their address.
 */
interface MailerInterface
{
    /**
     * @param string $html the body, an HTML document
     * @param string $from the address and the name the mail comes from (`Name <address>`)
     * @return bool whether the mail was handed to the mail system
     */
    public function send(string $to, string $subject, string $html, string $from): bool;
}
