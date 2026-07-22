<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Spora\Plugins\Memories\Http\AgentMemoryController;
use Spora\Plugins\Memories\Http\MemoryController;
use Spora\Plugins\Memories\MemoriesApp;
use Spora\Plugins\Memories\MemoriesPlugin;
use Spora\Plugins\Memories\Services\MemoryServiceInterface;
use Spora\Plugins\Memories\Tools\AgentMemoryTool;
use Spora\Plugins\Memories\Tools\GlobalMemoryTool;

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

it('register() wires MemoryServiceInterface -> MemoryService autowire', function (): void {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    (new MemoriesPlugin())->register($builder);

    $container = $builder->build();

    expect($container->has(MemoryServiceInterface::class))->toBeTrue();
    expect($container->has(MemoryController::class))->toBeTrue();
    expect($container->has(AgentMemoryController::class))->toBeTrue();
});
