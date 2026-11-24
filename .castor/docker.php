<?php

declare(strict_types=1);

namespace docker;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Console\Input\InputOption;

use function Castor\io;

#[AsTask(description: 'Starts the whole stack', aliases: ['start', 'up'])]
function start(): void
{
    compose(['up', '-d', '--wait', '--remove-orphans']);
    // What a pull brought: the stack never migrates by itself.
    \db\migrate();
    io()->success([
        'Cairn: '.app_url().' (admin@cairn.test, password "cairn")',
        'Components: '.app_url().'/storia',
        'Emails: '.mailpit_url(),
        'Single sign-on: https://authelia.localhost:9443 ("lea" has an account, "hugo" has none, password "cairn")',
        'Widget: http://staging.localhost:8001, a site to report problems from (claire.martin@maison-dupont.test, or "lea")',
    ]);
}

#[AsTask(description: 'Stops the stack, keeping the containers and the data', aliases: ['stop'])]
function stop(): void
{
    compose(['stop']);
}

#[AsTask(description: 'Restarts one service (php, cli, messenger, vite, storia, database, mailer) or all of them')]
function restart(string $service = ''): void
{
    compose(['restart', ...('' === $service ? [] : [$service])]);
}

#[AsTask(description: 'Removes the containers; --volumes also drops the database')]
function down(
    #[AsOption(description: 'Also remove the volumes (database data)', mode: InputOption::VALUE_NONE)]
    bool $volumes,
): void {
    if ($volumes && !io()->confirm('This deletes the database. Continue?', false)) {
        return;
    }

    compose(['down', '--remove-orphans', ...($volumes ? ['--volumes'] : [])]);
}

#[AsTask(description: 'Rebuilds the PHP image')]
function build(): void
{
    compose(['build', '--pull']);
}

#[AsTask(description: 'Lists the services and their state', aliases: ['ps'])]
function ps(): void
{
    compose(['ps']);
}

#[AsTask(description: 'Follows the logs of one service (php, cli, messenger, vite, storia, database, mailer) or of all of them', aliases: ['logs'])]
function logs(
    string $service = '',
    #[AsOption(description: 'Number of lines to show from the end of the logs')]
    int $tail = 100,
): void {
    compose(['logs', '--follow', '--tail', (string) $tail, ...('' === $service ? [] : [$service])]);
}
