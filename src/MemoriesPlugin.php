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
 * Plugin entry point for the Memories feature.
 *
 * Contributes one admin app (MemoriesApp), two LLM-callable tools
 * (`memory` agent-scoped, `global_memory` user-scoped), 12 REST routes
 * under `/api/v1/memories*`, DI bindings for the service and both
 * controllers, the `memories` migration, and the `memories-assistant`
 * agent template.
 */
final class MemoriesPlugin extends AbstractPlugin
{
    public function getName(): string
    {
        return (new MemoriesApp())->displayName();
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
        $r->addRoute('GET', '/api/v1/memories/{id}', [MemoryController::class, 'show'], $auth);
        $r->addRoute('PUT', '/api/v1/memories/{id}', [MemoryController::class, 'update'], $auth);
        $r->addRoute('DELETE', '/api/v1/memories/{id}', [MemoryController::class, 'destroy'], $auth);

        // Agent-scoped memories
        $r->addRoute('GET', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'index'], $auth);
        $r->addRoute('POST', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'store'], $auth);
        $r->addRoute('PATCH', '/api/v1/agents/{agentId}/memories/reorder', [AgentMemoryController::class, 'reorder'], $auth);
        $r->addRoute('GET', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'show'], $auth);
        $r->addRoute('PUT', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'update'], $auth);
        $r->addRoute('DELETE', '/api/v1/agents/{agentId}/memories/{memoryId}', [AgentMemoryController::class, 'destroy'], $auth);
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
