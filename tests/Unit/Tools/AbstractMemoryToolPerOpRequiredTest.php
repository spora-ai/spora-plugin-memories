<?php

declare(strict_types=1);

use Spora\Plugins\Memories\Tools\AbstractMemoryTool;
use Spora\Tools\Attributes\ToolParameter;

/**
 * Per-op `required[]` binding tests for AbstractMemoryTool.
 *
 * The two concrete tools (AgentMemoryTool, GlobalMemoryTool) inherit the
 * `#[ToolParameter]` attributes from this base class via PHP attribute
 * inheritance — verifying the base is equivalent to verifying both.
 *
 * Reads constructor arguments via reflection; does NOT instantiate the
 * attribute, sidestepping the spora-core `bool|array $required` signature
 * mismatch against the Packagist version. Once spora-core ships the new
 * signature AND the plugin bumps its dep, replace with
 * `ToolParameterSchemaBuilder::build(AbstractMemoryTool::class)`.
 */
function abstractMemoryToolParameterArgs(string $name): array
{
    $reflection = new ReflectionClass(AbstractMemoryTool::class);
    foreach ($reflection->getAttributes(ToolParameter::class) as $attribute) {
        $args = $attribute->getArguments();
        if (($args['name'] ?? null) === $name) {
            return $args;
        }
    }

    throw new RuntimeException("ToolParameter '{$name}' not declared on " . AbstractMemoryTool::class);
}

it('binds name to get, save, delete', function () {
    $expected = ['get', 'save', 'delete'];
    sort($expected);
    $actual = abstractMemoryToolParameterArgs('name')['required'];
    sort($actual);
    expect($actual)->toBe($expected);
});

it('binds content to save only', function () {
    expect(abstractMemoryToolParameterArgs('content')['required'])->toBe(['save']);
});
