<?php

/**
 * Safety-net autoloader for the extension's own classes.
 *
 * Composer normally autoloads `PeopleInside\PowCaptcha\` from `src/`. But when the
 * extension files are updated by hand (or Composer's autoloader was generated with
 * --optimize-autoloader / --classmap-authoritative and not regenerated), classes added
 * since the last `composer dump-autoload` cannot be found and the whole forum fails
 * to boot. This fallback resolves them straight from `src/`, so a stale classmap can
 * never take the forum down. It is registered after Composer's, so it only acts when
 * Composer has not found the class.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PeopleInside\\PowCaptcha\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
