<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories;

use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\Services\MemoryCommandInterface;
use Spora\Plugins\Memories\Services\MemoryCommandService;
use Spora\Plugins\Memories\Services\MemoryQueryInterface;
use Spora\Plugins\Memories\Services\MemoryQueryService;
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;
use Spora\Services\PrincipalService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point for the Memories feature.
 *
 * Schema version 2 introduces four breaking changes versus v1:
 *   1. memories.user_id → memories.principal_id  (post-0067 principal model)
 *   2. auto-increment BIGINT id → UUIDv7 CHAR(36)  (non-enumerable, time-ordered)
 *   3. implicit single-type → explicit `type` ENUM('plan','documentation','examples','context')
 *   4. new `replace` operation on both tools + a new POST .../replace endpoint
 *
 * Contributes one admin app (MemoriesApp), two LLM-callable tools
 * (`memory` agent-scoped, `global_memory` principal-scoped), 14 REST
 * routes under `/api/v1/memories*`, DI bindings for the service and both
 * controllers, the `memories` migrations, and the `memories-assistant`
 * agent template.
 */
final class MemoriesPlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const PATH_GLOBAL_MEMORY = '/api/v1/memories/{id}';
    private const PATH_AGENT_MEMORY  = '/api/v1/agents/{agentId}/memories/{memoryId}';
    private const AUTH               = [AuthMiddleware::class, CsrfMiddleware::class];

    public function getName(): string
    {
        return (new MemoriesApp())->displayName();
    }

    /**
     * Subscribe to the two boot-time events fired by spora-core. Listeners
     * run on the framework-wide EventDispatcher built by
     * {@see \Spora\Events\EventDispatcherFactory}.
     *
     * - {@see ContainerBuildingEvent} → fires once per process, before the
     *   DI container is built. {@see self::onContainerBuilding()} adds
     *   bindings for the plugin's interfaces + controllers so PHP-DI can
     *   autowire them at request time.
     * - {@see RoutesRegisteringEvent} → fires per request, after the host
     *   routes are registered. {@see self::onRoutesRegistering()} adds the
     *   14 `/api/v1/memories*` routes behind Auth + CSRF.
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Wire the service interfaces → concrete class autowire + the two
     * controllers. Adding explicit bindings here is necessary because the
     * host `App` does not know about the plugin's memory interfaces;
     * without these definitions, resolving either controller at
     * request-dispatch time would fail with `EntryNotFoundException`. The
     * v2 split registered two narrow interfaces (`MemoryQueryInterface`,
     * `MemoryCommandInterface`) so each side of the read/write split stays
     * under Sonar's per-class method-count ceiling — controllers depend
     * on whichever interfaces they actually call. `PrincipalService` is
     * already globally registered in spora-core; we re-list it here so
     * the plugin's container works when loaded standalone (e.g. in unit
     * tests that skip the host boot path).
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            MemoryQueryInterface::class   => \DI\autowire(MemoryQueryService::class),
            MemoryCommandInterface::class => \DI\autowire(MemoryCommandService::class),
            MemoryController::class       => \DI\autowire(),
            AgentMemoryController::class  => \DI\autowire(),
            AgentMemoryTool::class        => \DI\autowire(),
            GlobalMemoryTool::class       => \DI\autowire(),
            PrincipalService::class       => \DI\autowire(),
        ]);
    }

    /**
     * Register the 14 `/api/v1/memories*` routes behind Auth + CSRF.
     * Path strings mirror spora-core's pre-extraction paths verbatim so
     * the frontend bundle (which has been calling these endpoints since
     * 0.8.x) Just Works.
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $routes = $event->routes();

        // Global (principal-scoped) memories
        $routes->addRoute('GET', '/api/v1/memories', [MemoryController::class, 'index'], self::AUTH);
        $routes->addRoute('POST', '/api/v1/memories', [MemoryController::class, 'store'], self::AUTH);
        $routes->addRoute('PATCH', '/api/v1/memories/reorder', [MemoryController::class, 'reorder'], self::AUTH);
        $routes->addRoute('GET', self::PATH_GLOBAL_MEMORY, [MemoryController::class, 'show'], self::AUTH);
        $routes->addRoute('PUT', self::PATH_GLOBAL_MEMORY, [MemoryController::class, 'update'], self::AUTH);
        $routes->addRoute('POST', '/api/v1/memories/{id}/replace', [MemoryController::class, 'replace'], self::AUTH);
        $routes->addRoute('DELETE', self::PATH_GLOBAL_MEMORY, [MemoryController::class, 'destroy'], self::AUTH);

        // Agent-scoped memories
        $routes->addRoute('GET', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'index'], self::AUTH);
        $routes->addRoute('POST', '/api/v1/agents/{agentId}/memories', [AgentMemoryController::class, 'store'], self::AUTH);
        $routes->addRoute('PATCH', '/api/v1/agents/{agentId}/memories/reorder', [AgentMemoryController::class, 'reorder'], self::AUTH);
        $routes->addRoute('GET', self::PATH_AGENT_MEMORY, [AgentMemoryController::class, 'show'], self::AUTH);
        $routes->addRoute('PUT', self::PATH_AGENT_MEMORY, [AgentMemoryController::class, 'update'], self::AUTH);
        $routes->addRoute('POST', '/api/v1/agents/{agentId}/memories/{memoryId}/replace', [AgentMemoryController::class, 'replace'], self::AUTH);
        $routes->addRoute('DELETE', self::PATH_AGENT_MEMORY, [AgentMemoryController::class, 'destroy'], self::AUTH);
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
        return 2;
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
