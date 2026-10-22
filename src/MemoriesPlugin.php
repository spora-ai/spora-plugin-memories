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
     * The two boot-time events: `ContainerBuildingEvent` fires once per
     * process, before the container is built; `RoutesRegisteringEvent` per
     * request, after the host's own routes are registered.
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
     * The host `App` does not know the plugin's memory interfaces, so these
     * bindings have to be explicit — resolving a controller without them
     * fails with `EntryNotFoundException`.
     *
     * The read and write contracts stay narrow on purpose: one interface per
     * side keeps each service class under Sonar's per-class method-count
     * ceiling, and a controller depends only on the half it calls.
     *
     * `PrincipalService` is already registered in spora-core; it is re-listed
     * here so the plugin's container also resolves when the host boot path is
     * skipped.
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
     * The `/api/v1/memories*` routes, behind Auth + CSRF.
     *
     * The path strings mirror spora-core's pre-extraction paths verbatim:
     * the frontend bundle calls them, so they must not change.
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

    /**
     * The plugin's `locales/` directory — one `<locale>.json` per language,
     * keyed by the English source string.
     *
     * @return string[]
     */
    public function translationsPaths(): array
    {
        return [
            __DIR__ . '/../locales',
        ];
    }
}
