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
castor app:db:fixtures                       # the demo story, as demo:reset does (alias: castor fixtures)
castor postgres                              # psql shell on the app database (alias: castor pg)
castor postgres -- select 1 from foobar      # one-off command database query
castor demo:reset                            # new database: 5 accounts (printed), Alice and Bob share a garden, Carol has hers
castor app:garden:adopt-orphans --email=... --name=... --city=...   # the data of before the gardens, to a new garden of a member
castor app:user:create --email=... --name=... --password=... [--admin|--approved]   # an account, e.g. the administrator
castor demo:frost [--date=YYYY-MM-DD]        # reminder check with the recorded frost forecast
castor mcp:stdio --user=alice@hop-garden.test   # MCP server over STDIO, as a member, in their garden (Claude Desktop, Claude Code...)
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
- FrankenPHP (service `frontend`), embeds the Mercure hub at `/.well-known/mercure`: every update private, no anonymous
  subscriber. The topics are URLs under `DEFAULT_URI` (`App\Mercure\Topics`): a conversation and the notifications of a
  member, the drafting of a care sheet of a garden. Every page of a member sets the subscriber cookie
  `mercure_access_token` (`grant_mercure_topics()`), for their topics and those of their current garden (URL patterns);
  the streams are `turbo_stream_from(topic, private: true)`. The cookie lasts an hour: a page left open renews it
  (`mercure_cookie_controller.js`). The subscriber key is its own (`MERCURE_SUBSCRIBER_JWT_KEY`)
- Symfony Messenger: `async` transport (Doctrine, `use_notify: true`, no retry: a chat message must not be
  answered twice) and `scheduler_default`, both consumed by the `worker_messenger` container
  (`castor start-workers` / `castor stop-workers`)
- Symfony UX. Turbo: Drive between pages, Frames for the card (edited and validated in place) and its reminders,
  Streams over Mercure (the chat, the reminder toasts) rendered by the worker with Twig from the same components. The
  worker has no session: what carries a signed form is loaded by the browser (the card, a lazy frame in the chat).
  Twig Components: anonymous in `templates/components/`, classes in `src/Twig/Components/`; one Live Component,
  `SpeciesPicker`
- Symfony AI: the `gardener` agent (`config/packages/ai.yaml`, prompt in `config/agent/gardener.md`) gets its
  tools from the MCP client `chat`, connected to this application's own MCP server on `/_mcp/agent`. Each request of
  that client carries the token of its run (`App\Mcp\Agent\AgentRunTokens`: user, garden, conversation, 15 minutes,
  signed with a key derived from `APP_SECRET`), issued when the request dispatches `RunAgent` or `DraftCareSheet`, sent by
  `App\Mcp\Agent\AgentRunHttpClient` (the `http_client` of the client, decorating `psr18.http_client`; in the tests,
  `KernelHttpClient`)
- MCP bundle: server `default` (`/_mcp` and STDIO: `app:mcp:stdio --user=<email> [--garden=<id>]`, as a member) and
  server `agent` (`/_mcp/agent`). Both stateless firewalls answer 401 without token (`App\Mcp\Security\McpEntryPoint`):
  `mcp_agent` takes the token of a run (`App\Mcp\Agent\Security\AgentRunAuthenticator`), `mcp` none until OAuth
  (phase 3, see `.claude/tmp/mcp-oauth-study.md`), whose authenticator will bind its token to the garden chosen, as
  `CurrentGarden::TOKEN_ATTRIBUTE` says; in the tests, `App\Tests\Utils\Mcp\McpClientAuthenticator` stands for it. Both
  have the tools of
  `src/Mcp/Tool/`, among them `list_spaces`, `propose_space` and `propose_planting` (their result carries a
  `validation_url`, the card for an external client). `default` adds `confirm_plant` (`src/Mcp/Confirmation/`),
  `agent` adds `offer_choices` (`src/Mcp/Chat/`). Resources in `src/Mcp/Resource/`, the prompt in `src/Mcp/Prompt/`
- Chat: the choices of `offer_choices` and the cards of `propose_plant`, `propose_space` and `propose_planting` are
  their own `ChatEvent`, Turbo Stream and history entry, whatever `APP_DEMO` (`App\Chat\ToolCards`);
  `App\Chat\ToolCallDisplay` only governs the "MCP" lines (demo mode only). Once tapped, a card sends its message
  to the agent as the user (`data-chat-notice`, see `assets/controllers/chat_controller.js`), and a tap on a choice
  sends its text
- Public pages: `/` (`HomeController`, route `home`: what Hop Garden takes care of) and the legal pages `/legal-notice`,
  `/terms` and `/privacy` (routes `legal_*` of `config/routes.yaml`, `TemplateController`, texts under `legal.*`). They
  extend `templates/public/layout.html.twig`: their own top bar (the language, *Log in* to `/login` and *Join the
  waiting list* to `/register`; for a member, *Open my garden* instead: `Public:JoinButton`), without the navigation of
  the application or the notification stream. A blank of the publisher on a legal page is a `Legal:ToComplete`, never a
  made-up value. The footer of `base.html.twig` links the legal pages on every page (`Legal:Links`, at the foot of the
  back-office too: `templates/admin/layout.html.twig`). The application starts at `/assistant` (route `chat`), where a
  login leads, and `/?message=` redirects there. The privacy policy lists every cookie, all strictly necessary (no
  banner): the session, `REMEMBERME`, `locale` and the double-submit one of the CSRF tokens.
  `LegalPagesTest::testThePrivacyPolicyListsTheRealCookies()` checks the first three against what a visitor and a member
  get: a new cookie goes in the policy.
  The captures of `assets/images/home/` (a draft care sheet, the reminder board) exist in each language: retake both
  when the card or the board changes
- Species: `/plants` lists every species (validated first) with my plants of each, in one query
  (`PlantRepository::findAllWithPlantings()`); `/plants/new` creates a draft by hand with `SpeciesPicker` (any form
  whose data implements `SpeciesPickerData`), re-checked against Trefle (`PickedSpecies`), with a `CareSheet::blank()`
  of low confidence; a species with a care sheet already redirects to it
- Gardens, the tenant: `App\Garden\Entity\Garden` (its name, its `GardenLocation`: a place found by the geocoding of
  Open-Meteo, `App\DataSource\OpenMeteo\GeocodingClient`, with its position and its timezone) and its members,
  `Membership` (`owner` or `member`, the oldest first). Every space, care sheet (`Plant`), plant (`Planting`), reminder (by
  its care sheet) and notification (one per member) belongs to one; a conversation of the chat to a member in one
  (`App\Chat\Entity\Conversation`, next to the table of the bundle). `App\Garden\CurrentGarden` is the only way to read
  it (`UserCurrentGarden`): the one bound to the token (an agent run, STDIO, OAuth later), else the one of the session,
  chosen in the menu (`GardenSwitcher`), else the one they joined first; none in the worker, whose messages carry it. A
  member without garden is sent to the wizard (`/gardens/new`, `NoGardenListener`). Its owner manages it
  (`/gardens/{id}/settings`); its members come with the invitations (phase 2). Deleting an account deletes its gardens
  of its own, with their photos and conversations, and leaves the shared ones to the oldest other member
  (`App\Account\AccountDeletion`). The quotas of the LLM count per garden (`App\Garden\GardenQuotas`, rate limiters
  `garden_agent_runs` and `garden_latin_names`)
- Spaces: `App\Garden\Entity\Space` carries the exposure and the soil; a `Planting` has an optional space (`SET NULL`),
  and its neighbours are the other plantings of its space. Pages `/spaces`, `/spaces/new`, `/spaces/{id}` and
  `/spaces/{id}/edit` (`SpaceController`); `RecommendationEngine` reads the exposure of the space
- Languages: English (default) and French (`App\Locale\Model\SupportedLocale`), a setting, never in the URL.
  `App\Locale\PreferredLocale` (`get()`, `of($member)`, `set()`, `isChosen()`) is the only way to read or change it,
  while the messages a request dispatches (`RunAgent`, `DraftCareSheet`) carry the locale captured when sent, which their
  handler applies with the `LocaleSwitcher`. The reminder check writes each notification, and renders each toast, in
  `PreferredLocale::of()` its recipient.
  - `App\Locale\UserPreferredLocale`: the `preferredLocale` of the member logged in (null until chosen; a registration
    keeps the choice of the visitor), else the `locale` cookie of a visitor, else the one of the application, a row of
    `App\Settings\Entity\Settings` that no page writes (the reminder check), emptied by `castor demo:reset`. A choice
    goes to the account and to the cookie. `App\Locale\RequestLocaleListener` applies it after the firewall (7), which
    knows the member. Until one is chosen, `LocaleBanner` offers the language of the browser; the footer switch
    (`LocaleSwitch`, also in the top bar of a public page) posts to `/locale`, and *My account* has it too.
  - The agent gets the language at the end of its prompt (`App\Chat\InterfaceLanguagePrompt`) and writes its care
    sheets in it. When the interface is not in English, the species search asks the LLM for the Latin names of a name
    Trefle does not know (`App\Garden\LatinNameResolver`, cached per name).
- Translations: `translations/messages+intl-icu.{en,fr}.yaml` (ICU MessageFormat: `{name}`, `plural`, `select`; ’ for
  an apostrophe in French) and `translations/validators.{en,fr}.yaml` for the constraints. The enums translate their
  label (`TranslatableInterface`); dates, months and temperatures go through `App\Locale\IntlFormatter` (Twig:
  `day_and_month`, `long_date`, `day_and_time`, `temperature`, `month_abbr()`, `month_initial()`), in the timezone of
  the date. A stored notification keeps the language it was sent in
- `APP_DEMO=1` (default, in `.env`; `APP_DEMO=0` in `.env.local` for the real APIs, then restart the workers):
  Trefle and Open-Meteo answer from `fixtures/http/`, the Latin names of the species search from `fixtures/llm/`. Tests
  always run in demo mode (`.env.test`; Symfony does not load `.env.local` in the test env)
- Accounts (`App\Account\`): `Entity\User` (table `app_user`) registers, verifies its email (a link signed and dated by
  `UriSigner`, `App\Account\SignedLinks`), then waits on the list until the administrator approves or refuses it in
  EasyAdmin (`/admin`, `App\Controller\Admin\`, `ROLE_ADMIN`); each decision sends an email in the language of the
  member. `ROLE_USER` comes with the approval (`User::getRoles()`), and `Security\UserChecker` tells the others why after
  their password. Password reset: `Entity\PasswordResetRequest`, its token hashed, one hour, one per user. The pages pay
  no attention to whether an address has an account: the email tells its owner (`AccountMailer::sendAddressInUse()`).
  Accounts are created by `app:user:create` too (`castor app:user:create`)
- Security (`config/packages/security.yaml`): `form_login` on `/login`, which leads to `/assistant`, remember me,
  `login_throttling` (5 attempts in 15 minutes), logout on `/logout`, which leads to `/`. `access_control` denies by
  default (`ROLE_USER`, the chat included): public are the routes `home` and `legal_*`, `/login`, `/register…`,
  `/reset-password…` and `/locale`. `/_mcp` and `/_mcp/agent` have their own stateless firewalls, before `main`
- Mailer: Twig emails in `templates/email/`, translated, sent at once after the flush. In dev, Mailpit catches them
  (`MAILER_DSN` of `.env.dev`, its inbox on `mail.<root_domain>`, listed by `castor about`); in the tests,
  `null://null` and the email assertions of Symfony
- Demo accounts of `castor demo:reset`, password `hop-garden-demo` (`App\Factory\UserFactory::PASSWORD`):
  `admin@hop-garden.test` (the administrator, no garden), `alice@hop-garden.test` (English) and `bob@hop-garden.test`
  (French), who share "The allotment" (Alice its owner), `carol@hop-garden.test` (English, "Carol's balcony"),
  `dave@hop-garden.test` (on the waiting list)
- PostgreSQL 16: user/pass/db = `app`/`app`, DATABASE_URL already configured
- Traefik router, HTTPS on `<root_domain>` (see `castor.php`)
- Production: one image, `app` (the "Production stages" of the Dockerfile, `.dockerignore` at the root). A new
  environment variable read in production goes in the "Production image" section of `README.md`. The photos of the
  plantings are private, in `%kernel.share_dir%/uploads/plantings` (`var/share/<env>/`, `PlantingPhotoStorage`), served by
  `GardenController::photo()`
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
`castor qa:phpstan`, then `castor qa:phpunit`. After a change of the texts:
`castor builder -- bin/console debug:translation fr --only-missing`, which must find nothing (a test checks it too).

## Conventions

1. **Never invoke `docker compose` by hand**: use the `docker_compose()` /
   `docker_compose_run()` functions from `.castor/docker.php` to write new tasks.
2. **Never hardcode ports or project names**: git worktree support automatically
   isolates project/volumes/ports. Use `variable('project_name')` etc. The URL the browser opens is `PROJECT_URL`
   (`get_project_url()`, with the HTTPS port of the worktree): `DEFAULT_URI`, `MERCURE_PUBLIC_URL` and
   `MERCURE_JWT_ISSUER` derive from it in `docker-compose.yml`, so a worktree stack gets its own Mercure hub.
3. New recurring task? Make it a Castor task (`castor.php` or `.castor/*.php`),
   not a shell script.
4. QA tool dependencies live in `tools/<tool>/composer.json`
   (not in the root `composer.json`).
5. The human validates, always: the chat agent never gets a tool that writes. The `propose_*` tools write nothing but
   a draft care sheet: each shows a card, and only a tap of the user writes.
   - Validating a species validates its care sheet, nothing more: the card (`PlantController::validate()`), or an
     external MCP client after an explicit confirmation (`confirm_plant`), both through `App\Garden\PlantValidation`.
   - A plant of the garden (`/garden`, the only list of them; the species are listed on `/plants`) is created only by
     a tap on its "Add to my garden" card, or by the garden form (also from "Add to my garden" on the page of a
     species). A space likewise: its card, or the space form. The cards live in `App\Controller\ProposalController`.
   - A card is keyed by its `proposal_id`, a unique column of `Space` and `Planting`: once tapped it says "Added" or
     "Created", after a reload too, and a second tap creates nothing.
6. Read the time from `ClockInterface`, never `new \DateTimeImmutable()`: tests and `app:reminders:run --date`
   move the clock.
7. MCP tool names and arguments are snake_case, as in the brief; keep the names, and describe every argument in
   its schema so that a model can call the tool without documentation.
8. Tests run without an LLM: `tests/Utils/Chat/ScriptedGardener.php` scripts the model, everything else runs (the MCP
   client reaches the server through `tests/Utils/Mcp/KernelHttpClient.php`).
9. Data objects live in a sub-namespace of where they belong: entities and embeddables in an `Entity` sub-namespace
   (`App\Plant\Entity\Plant`, `App\Plant\Entity\CareSheet`), the other data objects (enums, value objects, DTO,
   form data) in `Model` (`App\Plant\Model\PlantStatus`, `App\DataSource\Trefle\Model\PlantCandidate`,
   `App\Plant\Form\Model\CareSheetFormData`). `config/services.yaml` excludes `src/**/Entity/` and `src/**/Model/`
   from the services, and the Doctrine mappings point at `src/<Domain>/Entity/`. Messenger messages go in `Message`,
   exceptions in `Exception`. Classes of static helpers (`ToolName`, `CareSheetInput`) are neither, and stay next to
   the services.
10. Three test suites, declared in `phpunit.xml.dist`: `tests/Unit` (no kernel), `tests/Integration` (the kernel and
    the database) and `tests/Functional` (HTTP requests through the kernel); run one with
    `castor --context=test qa:phpunit --testsuite=unit`. The helpers they share live in `tests/Utils/`
    (`App\Tests\Utils\`): every class of `tests/` that is not a concrete test, such as the doubles, the traits, the
    PHPUnit extensions and the abstract test cases. The Foundry factories are no test helpers: see 15.
11. No network in the tests: every HTTP client of the test environment goes through
    `App\Tests\Utils\Http\UnexpectedRequestGuard`, so an HTTP request that nothing simulates fails the test, even when
    the code under test catches the exception. Simulate it with a `MockHttpClient`, or with the recorded responses of
    the demo mode.
12. A piece of interface shown in two places, or rendered both by a page and by the worker, is a Twig Component, so
    that a page, a frame and a stream render the same markup.
13. `/garden/new?plant={id}` fixes the species of a new planting to a validated care sheet (no agent).
14. Every text of the interface goes through the translator, under a stable key, in every language of `translations/`
    (`tests/Unit/Translation/CatalogsTest.php` checks they have the same keys). What comes from the user, the agent or
    Trefle is not translated. The MCP tools and resources stay in English: they speak to a model. The messages the cards
    send to the agent once tapped (`data-chat-notice`, `chat.notice.*`) are in the language of the interface, as the
    bubble of the user shows them; nothing parses them, the agent reads them in any language.
15. Test and demo data come from Foundry, a dev dependency: the factories in `src/Factory/` (one per entity, and
    `CareSheetFactory` for the embeddable), the demo data in `src/Story/DemoStory.php` (`#[AsFixture(name: 'demo')]`,
    loaded by `foundry:load-fixtures demo` in a database recreated from the migrations). `config/services.yaml` and
    `.dockerignore` leave both out of production; `config/packages/zenstruck_foundry.yaml` registers the story in dev
    and test. A factory goes through the named constructors and the intent methods of the model, with named states
    (`validated()`, `withEnabledReminders()`, `of()`, `inSpace()`, `linkedTo()`, `drafting()`...), never through a
    setter or the reflection, and its dates are explicit. The tests get Foundry from its PHPUnit extension, without
    trait: no persistence in `tests/Unit`, and DAMA still rolls each test back.
16. A named service is injected with `#[Target('name')]` (see `debug:autowiring`), `#[Autowire(service:)]` only when no
    target exists.
17. Private by default: a new route needs `ROLE_USER`, an approved member, unless it joins the public ones of
    `access_control`. A functional test logs a member in with `App\Tests\Utils\Security\LogInTrait` (`logIn()`: the
    owner of the garden of the test, or a member who joins it; `logInWithoutGarden()`; `gardenOfTheTest()`), a live
    component with `actingAs()`. A page about an address (registration, password reset, email change) answers the same
    whether it has an account or not.
18. Nobody sees the garden of another (the tenancy):
    - A repository takes the garden explicitly (`findAllByName(Garden)`, `findOneInGarden(Garden, id)`...): no Doctrine
      filter, a forgotten garden shows in the code. The garden comes from `CurrentGarden` in a request or a tool, from the
      message in the worker.
    - A resource of a garden reached by id goes through a voter (`GardenResourceVoter`, `GardenVoter`,
      `PlantValidationVoter`: "the human validates"), refused by default, and answers 404 when refused, the same as a
      resource that does not exist: `#[IsGranted(..., statusCode: Response::HTTP_NOT_FOUND)]`, declared before
      `#[IsCsrfTokenValid]` so that a form sent to it says nothing either. A tool answers the error of an id that does not
      exist.
    - The messages of the worker carry the user and the garden (and the token of their run); their handler checks the
      user is still a member. The scheduler checks each garden at its position and in its timezone.
    - `tests/Functional/Security/GardenIsolationTest.php` lists every route, `tests/Integration/Mcp/GardenIsolationMcpTest.php`
      every MCP tool: a new one fails them until it says what a member of another garden gets. Their data comes from
      `App\Tests\Utils\Garden\TwoGardens`; the factories put what they make in the garden of the test
      (`App\Story\DefaultGardenStory`) unless told.
