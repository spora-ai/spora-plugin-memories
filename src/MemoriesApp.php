<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Memories feature.
 *
 * The host loads the frontend bundle from the plugin slug and entry filename.
 * The entry must match the frontend bundle's `build.lib.fileName()` value.
 */
final class MemoriesApp implements VueAppInterface
{
    public function name(): string
    {
        return 'memories';
    }

    public function displayName(): string
    {
        return 'Memories';
    }

    public function description(): string
    {
        return 'Persistent memory storage for agents and users.';
    }

    public function icon(): string
    {
        // `brain` is in the bundled icon palette — see spora-core/docs/07_plugins.md
        // § Bundled icons for the full list.
        return 'brain';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
