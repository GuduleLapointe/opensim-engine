<?php
/**
 * Loads the old global names of the engine when they are asked: the aliases of aliases.php, the classes of this folder.
 */

spl_autoload_register(static function (string $class): void {
    static $aliases = null;
    $aliases ??= require __DIR__ . '/aliases.php';

    if (isset($aliases[$class])) {
        class_alias($aliases[$class], $class);
    } elseif (preg_match('/^[A-Za-z0-9_]+$/', $class) && is_file(__DIR__ . "/$class.php")) {
        require __DIR__ . "/$class.php";
    }
});
