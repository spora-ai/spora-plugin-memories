<?php

declare(strict_types=1);

test('bundled assistant.json declares its required_plugins as a Composer vendor/name', function (): void {
    // The agent-template schema and the spora-core exporter/importer moved
    // to Composer package names (vendor/name) in the same change that
    // added PluginLoader::getSlugForPackageName(). A bare slug like
    // 'memories' now fails REQUIRED_PLUGINS_INVALID — the bundled
    // template must use the matching composer.json#name instead.
    $path = BASE_PATH . '/agent-templates/assistant.json';
    expect(is_file($path))->toBeTrue();

    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $payload */

    expect($payload['required_plugins'])->toBe(['spora-ai/spora-plugin-memories']);

    // Each entry must match the composer package regex. The agent-template
    // validator already enforces this; we re-pin the regex here so a
    // future schema loosening still fails this test.
    foreach ($payload['required_plugins'] as $entry) {
        expect((bool) preg_match(
            '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/',
            (string) $entry,
        ))->toBeTrue();
    }
});
