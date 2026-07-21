<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\MemoriesApp;
use Spora\Plugins\Memories\MemoriesPlugin;
use Spora\Plugins\Memories\Services\MemoryService;
use Spora\Plugins\Memories\Services\MemoryServiceInterface;
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;

it('advertises the plugin as "Memories"', function (): void {
    // The default AbstractPlugin::getName() would return "Memories" via reflection
    // on the class short name; we still override it explicitly so the value is
    // greppable and decoupled from the FQCN.
    $plugin = new MemoriesPlugin();

    expect($plugin->getName())->toBe('Memories');
});

it('contributes exactly one admin app', function (): void {
    // Lock the count so a future change that silently adds a second app surfaces
    // in tests instead of silently growing the operator's navbar.
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

it('declares schema version 1 so the DatabaseSchemaInstaller picks up migrations', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->schemaVersion())->toBe(1);
});

it('points migrationsPath() at the bundled database/migrations directory', function (): void {
    $plugin = new MemoriesPlugin();

    expect($plugin->migrationsPath())->toEndWith('/database/migrations')
        ->and(is_dir($plugin->migrationsPath()))->toBeTrue();
});

it('the migrations directory ships the slug-prefixed create-table migration', function (): void {
    $plugin = new MemoriesPlugin();

    $files = glob($plugin->migrationsPath() . '/memories_000001_*.php') ?: [];

    expect($files)->not->toBeEmpty();
});

it('ships an agent template at agent-templates/memories-assistant.json', function (): void {
    $plugin = new MemoriesPlugin();

    $paths = $plugin->agentTemplatePaths();
    expect($paths)->toHaveCount(1);

    $files = glob($paths[0] . '/memories-assistant.json') ?: [];
    expect($files)->not->toBeEmpty();
});

it('MemoriesApp satisfies VueAppInterface contract (name + entry)', function (): void {
    $app = new MemoriesApp();

    expect($app->name())->toBe('memories')
        ->and($app->entry())->toBe('main.js');
});

it('register() wires MemoryServiceInterface -> MemoryService autowire', function (): void {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    (new MemoriesPlugin())->register($builder);

    $container = $builder->build();

    // `has()` resolves the interface-name binding to the underlying autowire definition
    // without trying to instantiate MemoryService (which depends on AuthService etc. that
    // the unit-test container doesn't fully wire). The point of the assertion is that the
    // binding is in place — a missing binding would surface as `has() === false`.
    expect($container->has(MemoryServiceInterface::class))->toBeTrue();
    expect($container->has(MemoryController::class))->toBeTrue();
    expect($container->has(AgentMemoryController::class))->toBeTrue();
});
