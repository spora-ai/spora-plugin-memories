<?php

declare(strict_types=1);

// Bootstrap for PHPStan: define constants and Laravel helpers that are
// normally set at runtime by the host (public/index.php) but are not
// available during static analysis.

define('BASE_PATH', __DIR__);

require_once BASE_PATH . '/vendor/autoload.php';

if (!defined('Larastan\Larastan\LARAVEL_VERSION')) {
    define('Larastan\Larastan\LARAVEL_VERSION', '13.0.0');
}

// Laravel-style helpers that Eloquent / Larastan expect during analysis.
if (!function_exists('config_path')) {
    /** @SuppressWarnings("php:S100") — Laravel convention: snake_case path helper. */
    function config_path(string $path = ''): string {
        return BASE_PATH . '/config' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('app_path')) {
    /** @SuppressWarnings("php:S100") — Laravel convention: snake_case path helper. */
    function app_path(string $path = ''): string {
        return BASE_PATH . '/app' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('storage_path')) {
    /** @SuppressWarnings("php:S100") — Laravel convention: snake_case path helper. */
    function storage_path(string $path = ''): string {
        return BASE_PATH . '/storage' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
if (!function_exists('database_path')) {
    /** @SuppressWarnings("php:S100") — Laravel convention: snake_case path helper. */
    function database_path(string $path = ''): string {
        return BASE_PATH . '/database' . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}
