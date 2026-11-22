<?php

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\guard_min_version;
use function Castor\import;
use function Castor\io;
use function Castor\notify;
use function Castor\variable;
use function docker\about;
use function docker\build;
use function docker\docker_compose;
use function docker\docker_compose_run;
use function docker\get_project_url;
use function docker\get_services;
use function docker\up;
use function docker\workers_start;
use function docker\workers_stop;

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

guard_min_version('1.9.0');

import(__DIR__ . '/.castor');

/**
 * @return array{project_name: string, root_domain: string, registry: string}
 */
function create_default_variables(): array
{
    $projectName = 'hop-garden';
    $tld = 'test';

    return [
        'project_name' => $projectName,
        'root_domain' => "{$projectName}.{$tld}",
        // Of the production image (castor docker:push -c prod)
        'registry' => 'ghcr.io/lyrixx/hop-garden',
    ];
}

#[AsTask(description: 'Builds and starts the infrastructure, then install the application (composer, ...)')]
function start(): void
{
    io()->title('Starting the stack');

    workers_stop();
    build();
    install();
    up(profiles: ['default']); // We can't start worker now, they are not installed
    migrate();
    workers_start();

    notify('The stack is now up and running.');
    io()->success('The stack is now up and running.');

    about();
}

#[AsTask(description: 'Installs the application (composer, ...)', namespace: 'app', aliases: ['install'])]
function install(): void
{
    io()->title('Installing the application');

    if (!isset(get_services()['builder'])) {
        io()->comment('No builder service in this stack: the application is embedded in the image, nothing to install.');

        return;
    }

    // Downloading the dependencies is no test: in the test environment (test and ci contexts), every HTTP client
    // goes through App\Tests\Utils\Http\UnexpectedRequestGuard, and importmap:install could not reach the CDN
    $c = context()->withEnvironment(['APP_ENV' => 'dev']);

    io()->section('Installing PHP dependencies');
    docker_compose_run(['composer', 'install', '-n', '--prefer-dist', '--optimize-autoloader'], c: $c);

    if (is_file(variable('root_dir') . '/importmap.php')) {
        io()->section('Installing importmap');
        docker_compose_run(['bin/console', 'importmap:install'], c: $c);
    }

    qa\install();
}

#[AsTask(description: 'Clears the application cache', namespace: 'app', aliases: ['cache-clear'])]
function cache_clear(): void
{
    io()->title('Clearing the application cache');

    docker_compose_run(['rm', '-rf', 'var/cache/']);
    // On the very first run, the vendor does not exist yet
    if (is_dir(variable('root_dir') . '/vendor')) {
        docker_compose_run(['bin/console', 'cache:warmup'], c: context()->withAllowFailure());
    }
}

#[AsTask(description: 'Migrates database schema', namespace: 'app:db', aliases: ['migrate'])]
function migrate(): void
{
    io()->title('Migrating the database schema');

    docker_compose_run(['bin/console', 'doctrine:database:create', '--if-not-exists']);
    docker_compose_run(['bin/console', 'doctrine:migration:migrate', '-n', '--allow-no-migration', '--all-or-nothing']);
}

#[AsTask(description: 'Loads fixtures', namespace: 'app:db', aliases: ['fixtures'])]
function fixtures(): void
{
    io()->title('Loads fixtures');

    docker_compose_run(['bin/console', 'doctrine:fixture:load', '-n']);
}

#[AsTask(description: 'Resets the demo data: 2 validated plants with their reminders, their plantings in 3 spaces, no notification', namespace: 'demo', name: 'reset')]
function demo_reset(): void
{
    io()->title('Resetting the demo data');

    docker_compose_run(['bin/console', 'doctrine:fixture:load', '-n', '--purge-with-truncate']);

    io()->success('Demo data loaded.');
}

#[AsTask(description: 'Runs the reminder check against the recorded frost forecast (the toast shows up live)', namespace: 'demo', name: 'frost')]
function demo_frost(
    #[AsOption(description: 'Date to simulate (YYYY-MM-DD), defaults to today')]
    ?string $date = null,
): void {
    io()->title('Checking reminders with the recorded frost forecast');

    $command = ['bin/console', 'app:reminders:run'];
    if (null !== $date) {
        $command[] = '--date=' . $date;
    }

    // The recorded forecast is served in demo mode only, whatever the keys in .env.local
    $c = context()->withData(['docker_compose_run_environment' => ['APP_DEMO' => '1']]);

    docker_compose_run($command, c: $c);
}

#[AsTask(description: 'Serves the MCP server over STDIO, for Claude Desktop, Claude Code, Cursor... (the stack must be started)', namespace: 'mcp', name: 'stdio')]
function mcp_stdio(): int
{
    // Nothing but the JSON-RPC messages may reach STDOUT
    $c = context()->withInput(\STDIN)->withPty(false)->withTty(false)->withAllowFailure();

    // The validation URLs of propose_plant, propose_space and propose_planting open the stack of this checkout
    $defaultUri = 'DEFAULT_URI=' . get_project_url(variable('root_domain'));

    return (int) docker_compose(['run', '--rm', '-T', '--no-deps', '-e', $defaultUri, 'builder', 'php', 'bin/console', 'mcp:server', 'default', '--no-debug'], $c, profiles: ['*'])->getExitCode();
}
