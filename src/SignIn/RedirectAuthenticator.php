<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * Signs an avatar in by sending the person to the grid, which asks for the password on its own page and sends them back: the
 * password is never given to the application. Two steps, in two requests: begin() gives the address to send the person to
 * (or says why it cannot), and complete() judges what the grid sent back to the address of the application.
 */
interface RedirectAuthenticator
{
    /**
     * @param string $name the avatar name, as typed
     * @param string $returnTo the address of the application the grid sends the person back to
     */
    public function begin(string $name, string $returnTo): SignInRedirect|SignInResult;

    /**
     * @param SignInRedirect $redirect what begin() gave, kept (once) for this person
     * @param string $queryString the raw query string of the request the grid sent the person back with
     */
    public function complete(SignInRedirect $redirect, string $queryString): SignInResult;
}
