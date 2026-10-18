<?php

namespace OpenSim\Exceptions;

/**
 * The setup cannot go on: what went wrong is already on screen, the program
 * ends with an error code instead of going back to the menu.
 */
final class SetupFailedException extends \RuntimeException {}
