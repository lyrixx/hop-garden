# AGENTS.md

Instructions for AI agents working on this project.

## Fundamental rule: never run PHP/Composer on the host machine

Everything goes through the `builder` container, via Castor:

```bash
castor builder -- bin/console cache:clear    # one-off command
castor builder -- bin/console make:migration # one-off command
```

Host prerequisites only: Docker, Bash, Castor.

## Essential commands

```bash
castor start                                 # build + install + up + migrate + start workers
castor stop                                  # stop the stack
castor logs [--service=service]              # logs (frontend, worker_messenger, postgres, ...)
castor app:install                           # composer install + importmap:install + qa:install
castor app:db:migrate                        # Doctrine migrations (alias: castor migrate)
castor app:db:fixtures                       # fixtures (alias: castor fixtures)
castor postgres                              # psql shell on the app database (alias: castor pg)
castor postgres -- select 1 from foobar      # one-off command database query
castor demo:reset                            # 2 validated plants with their reminders, 3 plantings in 3 spaces (one empty)
castor demo:frost [--date=YYYY-MM-DD]        # reminder check with the recorded frost forecast
castor mcp:stdio                             # MCP server over STDIO (Claude Desktop, Claude Code...)
```

Docker / workers:

```bash
castor docker:build [--service=service]
castor docker:up [--service=service]
castor start-workers                         # start the Messenger consumer (worker_messenger)
castor stop-workers
```

## Castor contexts

The context changes how tasks are executed (`APP_ENV`, compose files, etc.):

```bash
castor --context=test qa:phpunit             # APP_ENV=test, for tests
castor --context=ci ...                      # like test, tuned for CI
castor --context=prod start                  # the production image, in a minimal stack (README, "Production image")
```

Always run tests and anything touching the database with `--context=test`.
Without option, the `default` context applies.

## Stack

- Symfony at the repo root (docroot = `public/`)
- FrankenPHP (service `frontend`), embeds the Mercure hub at `/.well-known/mercure`
- Symfony Messenger: `async` transport (Doctrine, `use_notify: true`, no retry: a chat message must not be
  answered twice) and `scheduler_default`, both consumed by the `worker_messenger` container
  (`castor start-workers` / `castor stop-workers`)
- Symfony UX. Turbo: Drive between pages, Frames for the card (edited and validated in place) and its reminders,
  Streams over Mercure (the chat, the reminder toasts) rendered by the worker with Twig from the same components. The
  worker has no session: what carries a signed form is loaded by the browser (the card, a lazy frame in the chat).
  Twig Components: anonymous in `templates/components/`, classes in `src/Twig/Components/`; one Live Component,
  `SpeciesPicker`
- Symfony AI: the `gardener` agent (`config/packages/ai.yaml`, prompt in `config/agent/gardener.md`) gets its
  tools from the MCP client `chat`, connected to this application's own MCP server on `/_mcp/agent`
- MCP bundle: server `default` (`/_mcp` and STDIO, with `confirm_plant`) and server `agent` (`/_mcp/agent`,
  without it). Tools in `src/Mcp/Tool/`, resources in `src/Mcp/Resource/`, the prompt in `src/Mcp/Prompt/`
- Spaces: `App\Garden\Model\Space` carries the exposure and the soil; a `Planting` has an optional space (`SET NULL`),
  and its neighbours are the other plantings of its space. Pages `/spaces`, `/spaces/new`, `/spaces/{id}` and
  `/spaces/{id}/edit` (`SpaceController`); `RecommendationEngine` reads the exposure of the space
- `APP_DEMO=1` (default, in `.env`; `APP_DEMO=0` in `.env.local` for the real APIs, then restart the workers):
  Trefle and Open-Meteo answer from `fixtures/http/`. Tests always run in demo mode (`.env.test`; Symfony
  does not load `.env.local` in the test env)
- PostgreSQL 16: user/pass/db = `app`/`app`, DATABASE_URL already configured
- Traefik router, HTTPS on `<root_domain>` (see `castor.php`)
- Production: one image, `app` (the "Production stages" of the Dockerfile, `.dockerignore` at the root). A new
  environment variable read in production goes in the "Production image" section of `README.md`
- AssetMapper/importmap only, no Node/yarn tooling in the application. The demo video (`video/`, Remotion and
  Playwright) is a host tool apart: `castor video:install`, `video:capture`, `video:render`

## QA — before considering a task done

Tools run inside the builder.

```bash
castor qa                                    # everything: cs + phpstan + twig-cs + phpunit
castor qa:cs [--dry-run]                     # PHP-CS-Fixer (.php-cs-fixer.php)
castor qa:phpstan [-b]                       # PHPStan level 9 (phpstan.neon)
castor qa:twig-cs                            # Twig-CS-Fixer
castor qa:phpunit                            # PHPUnit
```

After any PHP/Twig code change: `castor qa:cs --dry-run`,
`castor qa:phpstan`, then `castor qa:phpunit`.

## Conventions

1. **Never invoke `docker compose` by hand**: use the `docker_compose()` /
   `docker_compose_run()` functions from `.castor/docker.php` to write new tasks.
2. **Never hardcode ports or project names**: git worktree support automatically
   isolates project/volumes/ports. Use `variable('project_name')` etc.
3. New recurring task? Make it a Castor task (`castor.php` or `.castor/*.php`),
   not a shell script.
4. QA tool dependencies live in `tools/<tool>/composer.json`
   (not in the root `composer.json`).
5. The human validates, always: the chat agent never gets a tool that validates a plant. Only the user does,
   with the card (`PlantController::validate()`), or an external MCP client after an explicit confirmation. Both go
   through `App\Garden\PlantValidation`: a validated plant joins the garden (`/garden`, the only list).
6. Read the time from `ClockInterface`, never `new \DateTimeImmutable()`: tests and `app:reminders:run --date`
   move the clock.
7. MCP tool names and arguments are snake_case, as in the brief; keep the names, and describe every argument in
   its schema so that a model can call the tool without documentation.
8. Tests run without an LLM: `tests/Chat/ScriptedGardener.php` scripts the model, everything else runs (the MCP
   client reaches the server through `tests/Mcp/KernelHttpClient.php`).
9. Data objects live in a `Model` sub-namespace of where they belong: entities, embeddables, enums, value objects
   and DTO (`App\Plant\Model\Plant`, `App\DataSource\Trefle\Model\PlantCandidate`, form data in
   `App\Plant\Form\Model\`). `config/services.yaml` excludes `src/**/Model/` from the services, and the Doctrine
   mappings point at `src/<Domain>/Model/`. Messenger messages go in `Message`, exceptions in `Exception`. Classes
   of static helpers (`ToolName`, `CareSheetInput`) are neither, and stay next to the services.
10. Three test suites, declared in `phpunit.xml.dist`: `tests/Unit` (no kernel), `tests/Integration` (the kernel and
    the database) and `tests/Functional` (HTTP requests through the kernel); run one with
    `castor --context=test qa:phpunit --testsuite=unit`. The helpers they share stay outside these folders
    (`tests/Chat/`, `tests/Env/`, `tests/Factory/`, `tests/Http/`, `tests/Mcp/`, `tests/Mercure/`).
11. No network in the tests: every HTTP client of the test environment goes through
    `App\Tests\Http\UnexpectedRequestGuard`, so an HTTP request that nothing simulates fails the test, even when the
    code under test catches the exception. Simulate it with a `MockHttpClient`, or with the recorded responses of
    the demo mode.
12. A piece of interface shown in two places, or rendered both by a page and by the worker, is a Twig Component, so
    that a page, a frame and a stream render the same markup.
