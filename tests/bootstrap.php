<?php

declare(strict_types=1);

/*
 * Standalone test bootstrap for the audit module.
 *
 * The package is not yet composer-installed in the host repository, so the
 * host autoloader alone cannot resolve BAGArt\TelegramBotAudit classes.
 * We require the host vendor autoloader (which brings Pest/PHPUnit) and
 * register this package's PSR-4 autoloader inline.
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'BAGArt\\TelegramBotAudit\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
