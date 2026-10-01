<?php
/**
 * Self-contained PSR-4 autoloader for Quissly\Search\* -> lib/.
 *
 * Required by BOTH init.php and func.php. init.php only runs for an ACTIVE
 * add-on at bootstrap, but CS-Cart loads func.php during installation too (to
 * call settings handlers) — so the autoloader must live somewhere loaded in
 * every context. No Composer / vendor/autoload.php is required on the target.
 *
 * Idempotent: require_once guards re-inclusion, and the guard constant prevents
 * registering the closure twice.
 */

defined('BOOTSTRAP') or die('Access denied');

if (!defined('QUISSLY_SEARCH_AUTOLOAD_REGISTERED')) {
    define('QUISSLY_SEARCH_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Quissly\\Search\\';
        $length = strlen($prefix);

        if (strncmp($prefix, $class, $length) !== 0) {
            return;
        }

        $relative = substr($class, $length);
        $file = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}
