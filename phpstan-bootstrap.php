<?php

declare(strict_types=1);

use Composer\InstalledVersions;

// Bootstrap for PHPStan: define constants and Laravel helpers that are
// normally set at runtime by the host (public/index.php) but are not
// available during static analysis.

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

// Larastan reads the Laravel version as an unqualified constant, so the
// definition has to be global. A namespaced one is invisible to the classes
// in a sub-namespace: `Larastan\Larastan\Methods` resolves the name against
// its own namespace and then the global one, never the parent's.
//
// The value is read from the installed package rather than pinned: Larastan
// gates framework behaviour on it and loads the stub directories that match
// it, so a stale literal analyses the code against a Laravel that is not the
// one installed.
if (!defined('LARAVEL_VERSION') && InstalledVersions::isInstalled('illuminate/database')) {
    $laravelVersion = InstalledVersions::getVersion('illuminate/database');

    if ($laravelVersion !== null) {
        // Composer pads a tagged release to four segments ('13.43.0.0');
        // Larastan compares the constant against three-segment versions and
        // against three-segment stub directory names.
        define('LARAVEL_VERSION', preg_replace('/^(\d+\.\d+\.\d+)\.0$/', '$1', $laravelVersion) ?? $laravelVersion);
    }
}

// Laravel-style helpers that Eloquent / Larastan expect during analysis.
if (!function_exists('config_path')) {
    function config_path(string $path = ''): string { // NOSONAR (php:S100) — Laravel convention: snake_case path helper
        return BASE_PATH . '/config' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('app_path')) {
    function app_path(string $path = ''): string { // NOSONAR (php:S100) — Laravel convention: snake_case path helper
        return BASE_PATH . '/app' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string { // NOSONAR (php:S100) — Laravel convention: snake_case path helper
        return BASE_PATH . '/storage' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('database_path')) {
    function database_path(string $path = ''): string { // NOSONAR (php:S100) — Laravel convention: snake_case path helper
        return BASE_PATH . '/database' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
