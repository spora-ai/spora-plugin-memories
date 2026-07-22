<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Plugin-local test helpers and global Pest hooks. The host spora-core test
| suite defines the same `bootAuthLayer` / `bootAuth` / `jsonRequest` /
| `simulateLoggedInSession` / `clearSession` helpers; we redefine them here
| so the plugin's pest suite is self-contained and runs against the symlinked
| spora-core (via the plugin's `composer.json` repositories block).
|
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

// Suppress E_DEPRECATED originating from delight-im vendor packages.
// delight-im/auth v9.0.0 uses implicit nullable types (e.g. `callable $x = null`)
// which PHP 8.4+ deprecates. The warnings are harmless — nothing breaks at
// runtime — so we silence them rather than patching vendor.
set_error_handler(static function (...$handlerArgs): bool {
    [$errno, , $errfile] = $handlerArgs;

    if ($errno === E_DEPRECATED && str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)) {
        return true;
    }

    return false;
}, E_DEPRECATED);

// Shared test helpers (available to all test files)

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use AuthService.
 * Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): Spora\Auth\AuthService
{
    $pdo  = Illuminate\Database\Capsule\Manager::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false /* throttling off */);

    return new Spora\Auth\AuthService($auth);
}

/**
 * Create a JSON Request with an optional body array.
 */
function jsonRequest(string $method, string $uri, array $body = []): Symfony\Component\HttpFoundation\Request
{
    $content = $body !== [] ? json_encode($body) : '';

    return Symfony\Component\HttpFoundation\Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

/**
 * Simulate a logged-in session by populating the PHP session superglobal
 * the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]   = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL]     = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME]  = null;
}

/**
 * Clear the session, simulating a not-logged-in state.
 */
function clearSession(): void
{
    $_SESSION = [];
}

/**
 * Register a new user and simulate their session.
 * Returns the user ID.
 */
function bootAuth(Spora\Auth\AuthService $authService, string $email = 'test@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

uses()
    ->beforeEach(function () {
        Spora\Core\Database::resetBootState();
        $db = new Spora\Core\Database(['db_driver' => 'sqlite', 'db_path' => ':memory:']);
        $db->boot();
        Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

        // The plugin's `memories` table lives in the plugin's own migration. The host
        // DatabaseSchemaInstaller only runs plugin migrations when a PluginLoader
        // is wired up — which the plugin's pest suite doesn't do (it tests the plugin
        // directly, not via the host boot path). Manually run the plugin's migration
        // here so test code can SELECT/INSERT against `memories`.
        $migrationPath = __DIR__ . '/../database/migrations';
        foreach (glob($migrationPath . '/*.php') ?: [] as $migrationFile) {
            /** @SuppressWarnings("php:S2003") — migration returns an anonymous-class instance; `require_once` would skip on the second test in the same process and break test isolation. */
            $migration = require $migrationFile;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    })
    ->afterEach(function () {
        if (Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0) {
            Illuminate\Database\Capsule\Manager::connection()->rollBack();
        }
        Spora\Core\Database::resetBootState();
        Mockery::close();
    })
    ->in(__DIR__);
