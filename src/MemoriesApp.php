<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Memories feature.
 *
 * Implementing {@see VueAppInterface} (instead of {@see \Spora\Apps\AppInterface})
 * opts the app into the host SPA's generic `/apps/:appName` loader, which
 * lazy-imports `/plugins/<slug>/<entry>` and mounts the IIFE bundle inside the
 * shared layout. The slug returned by {@see name()} (`memories`) is the host
 * SPA's route key; it is independent of the Composer package name
 * (`spora-ai/spora-plugin-memories`) and of the on-disk bundle directory
 * (which is derived from the *frontend* package's name by the
 * `SporaPluginFrontendInstaller` in `spora-installer`).
 *
 * The {@see entry()} value (`main.js`) MUST match the frontend bundle's
 * `build.lib.fileName()` from `spora-plugin-memories-frontend/vite.config.ts`.
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
