<?php

declare(strict_types=1);

/**
 * Registers a PSR-4 style autoloader for the BaikalExt\ namespace.
 *
 * Safe to include multiple times and alongside Composer's autoloader. This lets
 * the extension's classes be loaded both from the CLI and from within Baikal's
 * web request (where our sabre plugin is registered).
 */

if (!defined('BAIKAL_EXT_BOOTSTRAPPED')) {
    define('BAIKAL_EXT_BOOTSTRAPPED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'BaikalExt\\';
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            return;
        }

        $relative = substr($class, $len);
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require $file;
        }
    });
}
