<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Memories feature.
 *
 * The entry must match the frontend bundle's `build.lib.fileName()` value —
 * the host loads the bundle from the plugin slug and that filename.
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
        // `brain` is a bundled icon name — see the plugin schema docs for the palette.
        return 'brain';
    }

    public function accent(): string
    {
        return 'emerald';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
