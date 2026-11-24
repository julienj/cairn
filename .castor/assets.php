<?php

declare(strict_types=1);

/*
 * In dev the "vite" service serves the assets with hot reload, and the "widget" service builds
 * the script of the widget again when it changes: nothing to build.
 */

namespace assets;

use Castor\Attribute\AsTask;

#[AsTask(description: 'Installs the npm packages and builds the assets for production into public/build/')]
function build(): void
{
    npm(['ci']);
    npm(['run', 'build']);
}

#[AsTask(description: 'Removes the production build, so that the pages use the Vite dev server again')]
function clean(): void
{
    in_container(['rm', '-rf', 'public/build']);
    compose(['restart', 'vite']);
}
