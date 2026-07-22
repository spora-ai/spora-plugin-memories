<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories;

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\Services\MemoryService;
use Spora\Plugins\Memories\Services\MemoryServiceInterface;
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;

/**
 * Plugin entry point for the Memories feature. Extending {@see AbstractPlugin}
 * (instead of implementing {@see \Spora\Plugins\PluginInterface} directly) means
 * we only override the hooks we actually use; every other extension point
 * inherits its no-op default.
 *
 * Plugin contract wiring (see §Plugin contract wiring in the implementation plan):
 *
 * - {@see apps()} contributes {@see MemoriesApp} (a {@see \Spora\Apps\VueAppInterface}).
 *   The host AppRegistry merges this in at container-build time, so the navbar
 *   picks up the Memories tile via `GET /api/v1/apps`.
 * - {@see tools()} contributes the two LLM-callable tools `memory` (agent-scoped)
 *   and `global_memory` (cross-agent, per user).
 * - {@see register()} binds the service-interface → concrete-class autowire and
 *   the two controllers via `$builder->addDefinitions([...])`. PHP-DI's autowire
 *   resolves every ctor argument against existing container bindings, so adding
 *   `MemoryController::class => autowire()` is enough — no explicit constructor
 *   parameters are needed.
 * - {@see routes()} registers the 12 `/api/v1/memories*` endpoints behind
 *   {@see AuthMiddleware} + {@see CsrfMiddleware} (same chain as the rest of
 *   spora-core). The plugin's routes are added per-request after the host's
 *   `RouteDefinitions::register()`, so they are visible from the same dispatcher.
 *
 * Schema lifecycle:
 *
 * - {@see schemaVersion()} returns 1 so that {@see \Spora\Core\DatabaseSchemaInstaller}
 *   sees this plugin as migration-bearing. The `memories` migration file is
 *   prefixed with the plugin slug (`memories_000001_*`) per the filename contract
 *   the installer enforces via `validateMigrationFilenames()`.
 * - {@see migrationsPath()} returns the bundled `database/migrations/` directory.
 * - {@see agentTemplatePaths()} returns the bundled `agent-templates/` directory
 *   so the {@see \Spora\AgentTemplates\AgentTemplateScanner} picks up
 *   `memories-assistant.json` on every boot.
 */
final class MemoriesPlugin extends AbstractPlugin
{
    private const ROUTE_MEMORY_ITEM       = '/api/v1/memories/{id}'; // NOSONAR (php:S1192) — const value is the only literal definition; route calls use self::ROUTE_*_ITEM
    private const ROUTE_AGENT_MEMORY_ITEM = '/api/v1/agents/{agentId}/memories/{memoryId}'; // NOSONAR (php:S1192) — const value is the only literal definition; route calls use self::ROUTE_*_ITEM

    public function getName(): string
    {
        return 'Memories';
    }

    /**
     * Wire the service interface → concrete class autowire + the two controllers.
     * Invoked once per process during boot, before the container is built.
     *
     * Adding explicit bindings here is necessary because the host `App` does not
     * know about `MemoryServiceInterface`; without these definitions, resolving
     * either controller at request-dispatch time would fail with
     * `EntryNotFoundException`. PHP-DI autowire resolves the
     * `MemoryService(AuthService…)` ctor for us once we point the interface at
     * the concrete class.
     *
     * @param ContainerBuilder $builder
     */
    public function register(ContainerBuilder $builder): void
    {
        $builder->addDefinitions([
            MemoryServiceInterface::class => \DI\autowire(MemoryService::class),
            MemoryController::class      => \DI\autowire(),
            AgentMemoryController::class => \DI\autowire(),
            AgentMemoryTool::class       => \DI\autowire(),
            GlobalMemoryTool::class      => \DI\autowire(),
        ]);
    }

    /**
     * Register the 12 `/api/v1/memories*` routes behind Auth + CSRF.
     *
     * Invoked per request after the host's `RouteDefinitions::register()`. Path
     * strings mirror spora-core's pre-extraction paths verbatim so the frontend
     * bundle (which has been calling these endpoints since 0.8.x) Just Works.
     *
     * @param MiddlewareRouteCollector $r
     */
    public function routes(MiddlewareRouteCollector $r): void
    {
        $auth = [AuthMiddleware::class, CsrfMiddleware::class];

        // Global (user-scoped) memories
        $r->addRoute('GET', '/api/v1/memories', [MemoryController::class, 'index'], $auth);
        $r->addRoute('POST', '/api/v1/memories', [MemoryController::class, 'store'], $auth);
        $r->addRoute('PATCH', '/api/v1/memories/reorder', [MemoryController::class, 'reorder'], $auth);
        $r->addRoute('GET', self::ROUTE_MEMORY_ITEM, [MemoryController::class, 'show'], $auth);
        $r->addRoute('PUT', self::ROUTE_MEMORY_ITEM, [MemoryController::class, 'update'], $auth);
        $r->addRoute('DELETE', self::ROUTE_MEMORY_ITEM, [MemoryController::class, 'destroy'], $auth);

        // Agent-scoped memories
        $r->addRoute('GET', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'index'], $auth);
        $r->addRoute('POST', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'store'], $auth);
        $r->addRoute('PATCH', '/api/v1/agents/{agentId}/memories/reorder', [AgentMemoryController::class, 'reorder'], $auth);
        $r->addRoute('GET', self::ROUTE_AGENT_MEMORY_ITEM, [AgentMemoryController::class, 'show'], $auth);
        $r->addRoute('PUT', self::ROUTE_AGENT_MEMORY_ITEM, [AgentMemoryController::class, 'update'], $auth);
        $r->addRoute('DELETE', self::ROUTE_AGENT_MEMORY_ITEM, [AgentMemoryController::class, 'destroy'], $auth);
    }

    /**
     * @return array<int, class-string<\Spora\Apps\AppInterface>>
     */
    public function apps(): array
    {
        return [
            MemoriesApp::class,
        ];
    }

    /**
     * @return array<int, class-string<\Spora\Tools\ToolInterface>>
     */
    public function tools(): array
    {
        return [
            AgentMemoryTool::class,
            GlobalMemoryTool::class,
        ];
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/../database/migrations';
    }

    /**
     * @return string[]
     */
    public function agentTemplatePaths(): array
    {
        return [__DIR__ . '/../agent-templates'];
    }
}
