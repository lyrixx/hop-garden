<?php

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
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
use function docker\docker_exit_code;
use function docker\get_project_url;
use function docker\get_services;
use function docker\has_router;
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

    load_demo_story();
}

/**
 * @param list<string> $rawTokens
 */
#[AsTask(description: 'Creates an account: --email=... --name=... --password=..., with --admin for the administrator of a new installation (or --approved)', namespace: 'app:user', name: 'create')]
function user_create(#[AsRawTokens] array $rawTokens = []): int
{
    io()->title('Creating an account');

    return docker_exit_code(['bin/console', 'app:user:create', ...$rawTokens]);
}

/**
 * @param list<string> $rawTokens
 */
#[AsTask(description: 'Gives the data of before the gardens to a new garden of a member: --email=... [--name=...] --city=... (the migrations around it included)', namespace: 'app:garden', name: 'adopt-orphans')]
function garden_adopt_orphans(#[AsRawTokens] array $rawTokens = []): int
{
    io()->title('Adopting the data of before the gardens');

    // The last migration before the one that refuses a row without garden (Version20261123120000). Never down: a
    // database past it has no orphan
    $lastWithOrphans = 'DoctrineMigrations\Version20261123110000';
    docker_compose_run(['bin/console', 'doctrine:database:create', '--if-not-exists']);
    $current = explode(' ', trim(docker_compose_run(['bin/console', 'doctrine:migrations:current', '--no-ansi'], c: context()->withQuiet())->getOutput()))[0];
    if (strcmp($current, $lastWithOrphans) < 0) {
        docker_compose_run(['bin/console', 'doctrine:migration:migrate', $lastWithOrphans, '-n', '--all-or-nothing']);
    }

    $exitCode = docker_exit_code(['bin/console', 'app:garden:adopt-orphans', ...$rawTokens]);
    if (0 !== $exitCode) {
        return $exitCode;
    }

    migrate();

    return 0;
}

#[AsTask(description: 'Moves the photos of the plantings out of public/uploads, to the private storage of var/share', namespace: 'app:plantings', name: 'move-photos')]
function plantings_move_photos(): int
{
    io()->title('Moving the photos of the plantings');

    return docker_exit_code(['bin/console', 'app:plantings:move-photos']);
}

#[AsTask(description: 'Resets the demo data: 5 accounts, 2 gardens (one shared by Alice and Bob), their plants, spaces and reminders, no notification', namespace: 'demo', name: 'reset')]
function demo_reset(): void
{
    io()->title('Resetting the demo data');

    load_demo_story();

    io()->success('Demo data loaded.');

    // Keep in sync with App\Story\DemoStory, and App\Factory\UserFactory::PASSWORD
    io()->section('Log in at ' . get_project_url(variable('root_domain')) . '/login');
    io()->table(['Email', 'Password', 'Account', 'Garden'], [
        ['admin@hop-garden.test', 'hop-garden-demo', 'the administrator, of the back-office too (/admin)', 'none: the wizard makes one'],
        ['alice@hop-garden.test', 'hop-garden-demo', 'a member, in English', 'The allotment, its owner'],
        ['bob@hop-garden.test', 'hop-garden-demo', 'a member, in French', 'The allotment, a member'],
        ['carol@hop-garden.test', 'hop-garden-demo', 'a member, in English', 'Carol\'s balcony, its owner'],
        ['dave@hop-garden.test', 'hop-garden-demo', 'on the waiting list: approve him in the back-office', 'none yet'],
    ]);
    if (has_router()) {
        io()->comment('Their emails land in Mailpit: ' . get_project_url('mail.' . variable('root_domain')));
    }
}

/**
 * App\Story\DemoStory, in a database that Foundry recreates with the migrations.
 */
function load_demo_story(): void
{
    // Recreating the database cuts the connections to it: the Messenger worker would stop with its own
    workers_stop();

    try {
        docker_compose_run(['bin/console', 'foundry:load-fixtures', 'demo', '-n']);
    } finally {
        workers_start();
    }
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

    // The recorded forecast is served in demo mode only, whatever the keys in .env.local. The toasts go to the Mercure
    // topics of the pages of this checkout, under its URL (App\Mercure\Topics)
    $c = context()->withData(['docker_compose_run_environment' => ['APP_DEMO' => '1', 'DEFAULT_URI' => get_project_url(variable('root_domain'))]]);

    docker_compose_run($command, c: $c);
}

#[AsTask(description: 'Serves the MCP server over STDIO, for Claude Desktop, Claude Code, Cursor..., as a member: --user=<email> [--garden=<slug>] (the stack must be started)', namespace: 'mcp', name: 'stdio')]
function mcp_stdio(
    #[AsOption(description: 'The email of the member the tools act for')]
    string $user = '',
    #[AsOption(description: 'The slug of their garden, as in its URL, the one they joined first by default')]
    ?string $garden = null,
): int {
    // Nothing but the JSON-RPC messages may reach STDOUT
    $c = context()->withInput(\STDIN)->withPty(false)->withTty(false)->withAllowFailure();

    // The validation URLs of propose_plant, propose_space and propose_planting open the stack of this checkout
    $defaultUri = 'DEFAULT_URI=' . get_project_url(variable('root_domain'));

    $command = ['php', 'bin/console', 'app:mcp:stdio', '--user=' . $user, '--no-debug'];
    if (null !== $garden) {
        $command[] = '--garden=' . $garden;
    }

    return (int) docker_compose(['run', '--rm', '-T', '--no-deps', '-e', $defaultUri, 'builder', ...$command], $c, profiles: ['*'])->getExitCode();
}
