<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\MemoriesApp;
use Spora\Plugins\Memories\MemoriesPlugin;
use Spora\Plugins\Memories\Services\MemoryCommandInterface;
use Spora\Plugins\Memories\Services\MemoryQueryInterface;
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;
use Spora\Services\PrincipalService;
use Symfony\Component\EventDispatcher\EventDispatcher;

it('advertises the plugin as the app\'s display name', function (): void {
    $plugin = new MemoriesPlugin();

    // Derived from MemoriesApp::displayName() so the plugin name and the
    // navbar label can't drift apart.
    expect($plugin->getName())->toBe((new MemoriesApp())->displayName());
});

it('contributes exactly one admin app', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->apps())->toHaveCount(1);
});

it('registers MemoriesApp via apps()', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->apps())->toContain(MemoriesApp::class);
});

it('contributes exactly two memory tools', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->tools())->toHaveCount(2);
});

it('lists AgentMemoryTool and GlobalMemoryTool via tools()', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->tools())->toContain(AgentMemoryTool::class, GlobalMemoryTool::class);
});

it('declares schema version 2 so the DatabaseSchemaInstaller picks up the v2 migration', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->schemaVersion())->toBe(2);
});

it('points migrationsPath() at the bundled database/migrations directory', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->migrationsPath())->toEndWith('/database/migrations')
        ->and(is_dir($plugin->migrationsPath()))->toBeTrue();
});

it('the migrations directory ships both the create-table and v2 migrations', function (): void {
    $plugin = new MemoriesPlugin();

    $v1 = glob($plugin->migrationsPath() . '/memories_000001_*.php') ?: [];
    $v2 = glob($plugin->migrationsPath() . '/memories_000002_*.php') ?: [];

    expect($v1)->not->toBeEmpty()
        ->and($v2)->not->toBeEmpty();
});

it('ships an agent template at agent-templates/assistant.json', function (): void {
    $plugin = new MemoriesPlugin();

    $paths = $plugin->agentTemplatePaths();
    expect($paths)->toHaveCount(1);

    $files = glob($paths[0] . '/assistant.json') ?: [];
    expect($files)->not->toBeEmpty();
});

it('MemoriesApp satisfies VueAppInterface contract (name + entry)', function (): void {
    $app = new MemoriesApp();

    expect($app->name())->toBe('memories')
        ->and($app->entry())->toBe('main.js');
});

it('subscribes to ContainerBuildingEvent and RoutesRegisteringEvent', function (): void {
    $events = MemoriesPlugin::getSubscribedEvents();

    expect($events)->toBe([
        ContainerBuildingEvent::class => 'onContainerBuilding',
        RoutesRegisteringEvent::class => 'onRoutesRegistering',
    ]);
});

it('onContainerBuilding wires all 7 DI bindings', function (): void {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new MemoriesPlugin());
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $container = $builder->build();

    expect($container->has(MemoryQueryInterface::class))->toBeTrue()
        ->and($container->has(MemoryCommandInterface::class))->toBeTrue()
        ->and($container->has(MemoryController::class))->toBeTrue()
        ->and($container->has(AgentMemoryController::class))->toBeTrue()
        ->and($container->has(AgentMemoryTool::class))->toBeTrue()
        ->and($container->has(GlobalMemoryTool::class))->toBeTrue()
        ->and($container->has(PrincipalService::class))->toBeTrue();
});

it('onRoutesRegistering registers 14 routes behind Auth + Csrf', function (): void {
    $routes = new MiddlewareRouteCollector(new FastRoute\RouteParser\Std(), new FastRoute\DataGenerator\GroupCountBased());

    (new MemoriesPlugin())->onRoutesRegistering(new RoutesRegisteringEvent($routes));

    $reflection = new ReflectionClass($routes);
    $dataGen = $reflection->getProperty('dataGenerator');
    $generator = $dataGen->getValue($routes);

    $genReflection = new ReflectionClass($generator);

    $staticRoutes = $genReflection->getProperty('staticRoutes');
    $staticRouteMap = $staticRoutes->getValue($generator);

    $variableRoutes = $genReflection->getProperty('methodToRegexToRoutesMap');
    $variableRouteMap = $variableRoutes->getValue($generator);

    $staticCount = array_sum(array_map('count', $staticRouteMap));
    $variableCount = array_sum(array_map('count', $variableRouteMap));

    expect($staticCount + $variableCount)->toBe(14);

    $replaceRoute = findRoute($variableRouteMap, '#/api/v1/memories/\(\[\^/\]\+\)/replace#');
    expect($replaceRoute)->not->toBeNull()
        ->and($replaceRoute->handler)->toBe([
            'handler' => [MemoryController::class, 'replace'],
            'middleware' => [AuthMiddleware::class, CsrfMiddleware::class],
        ]);
});

function findRoute(array $routeMap, string $regexPattern): ?FastRoute\Route
{
    foreach ($routeMap as $routes) {
        foreach ($routes as $route) {
            if (preg_match($regexPattern, $route->regex) === 1) {
                return $route;
            }
        }
    }

    return null;
}
