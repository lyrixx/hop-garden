# Hop Garden

Hop Garden manages the plants of your garden with an AI agent. Name a plant in the chat: the agent identifies it,
drafts its care sheet with a confidence level for every value, and you validate it. Then it reminds you when to
prune, deadhead and take cuttings, and warns you before frost.

[![The demo video: "I just planted a hydrangea on the terrace", a choice to tap, the care sheet to validate, the plant added to the terrace, then a frost alert](media/hop-garden.jpg)](media/hop-garden.mp4)

<sub>The demo video, 85 seconds: click the picture to play it. Recorded with a real LLM, in demo mode (Trefle and Open-Meteo answer from recorded responses).</sub>

**Symfony AI & MCP Hackathon** · Tags: `MCP` `Agent` `Symfony AI`

- **The human validates, always.** The agent only proposes: a care sheet, a space, a plant, each as a card. Nothing
  is written until you tap it: *Validate* on a care sheet, after reviewing the highlighted values, *Create the space*,
  *Add to my garden*. The agent of the web UI has no tool that writes.
- **The agent goes through MCP.** The application is an MCP server, and its own chat agent consumes it as an MCP
  client, over HTTP. Every tool call shows up in the chat. The same server works in Claude Desktop, Claude Code
  or Cursor.
- **Honest data.** Each value carries the confidence the agent gave it and its sources (Trefle or "LLM
  knowledge"). Values you change while reviewing count as verified.

## Run it in 5 minutes

Prerequisites: [Docker](https://docs.docker.com/get-docker/) and [Castor](https://castor.jolicode.com/getting-started/installation/).

```bash
git clone https://github.com/lyrixx/hop-garden.git && cd hop-garden
echo '127.0.0.1 hop-garden.test' | sudo tee -a /etc/hosts
echo 'ANTHROPIC_API_KEY=sk-ant-...' > .env.local
castor start
castor demo:reset   # the demo accounts and their three gardens: a rose and a lemon tree, with their reminders, in 3 spaces; an invitation for Erin
```

Open <https://hop-garden.test/assistant>, log in as `alice@hop-garden.test` (password `hop-garden-demo`) and type
`hortensia`: the chat of her garden, <https://hop-garden.test/g/the-allotment/assistant>. Bob (`bob@hop-garden.test`)
shares her garden, in French, and has another; Carol (`carol@hop-garden.test`) has her own.

That's all: the demo mode is on by default, so Trefle and Open-Meteo answer from the responses
stored in [`fixtures/http/`](fixtures/http/). Only the LLM key is needed. Hop Garden speaks English and French: see
[In English or in French](#in-english-or-in-french).

To use OpenAI instead of Anthropic, put this in `.env.local`:

```bash
AI_PLATFORM=openai
AI_MODEL=gpt-5.4-mini
OPENAI_API_KEY=sk-...
```

### The demo

1. Type `hortensia`. The agent calls `search_plant` and asks which hydrangea you have, a button per candidate.
2. Answer `paniculata`. It completes the sheet with its own knowledge, then calls `propose_plant`: the draft card
   shows up, its uncertain values highlighted.
3. Click *Edit* to correct a value, then *Save*: the card comes back in the conversation. Click *Validate*: the care
   sheet is validated and shows its reminder switches; turn on pruning and frost. The agent asks where it grows.
4. Tap a space, then *Add to my garden* on the card that follows: the card says *Added*, and *See my plant* opens your
   hydrangea in *My garden*: the plant you grow (where it grows, the care it got, the recommendations), apart from
   its species (the care sheet and its reminders, on the page of the species). Each links to the other.
5. *Species* (`/g/the-allotment/species`): every care sheet, its plate and my plants of it; *New species* without the
   assistant.
6. *My spaces*: describe the spots of your garden (balcony, north bed…) with their exposure and soil; the plants of a
   space share them and are neighbours. The recommendations read the exposure of the space.
7. Run `castor demo:frost`: the reminder check runs against the recorded cold snap, and a frost toast appears live,
   pushed by Mercure.

### Adding a plant with the agent

Say *"Je viens de planter un hortensia sur la terrasse"*, in any language. The agent answers in yours, turns
"hortensia" into its Latin name (Hydrangea), and at each step you type an answer or tap a choice or a card. Nothing is
written before a tap.

- **Known species, known space.** A validated care sheet of a hydrangea and a space that matches "terrasse": the agent
  asks which one if several fit, then shows an *Add to my garden* card (species, space, name). A tap adds the plant;
  the card says *Added*, with a link to *My plant*.
- **New space.** No space matches: a space card comes first, its name prefilled, its exposure and soil to tap. *Create
  the space*, then the plant card.
- **New species.** No validated care sheet: the agent searches Trefle, asks which hydrangea you have, and proposes a
  draft care sheet. Once you validate it, the space and the plant follow, as above.

A card keeps its outcome: reload the page and it still says *Added* or *Created*, and a second tap adds nothing.

### In English or in French

Hop Garden speaks English and French. The language is a setting of each member, never of the URL: with a browser in
French, the pages stay in English until you choose, under a band in French, *Passer en français* or *Rester en
anglais*. The band does not come back, and the footer or *My account* switches the language later. A visitor's choice
stays in a cookie, and goes to the account they register. In `castor demo:reset`, Alice speaks English and Bob French.

- The assistant answers in the language you write in, and in the one of the interface when a tap on a choice does not
  tell. A tapped card tells it in the language of the interface (*J’ai créé l’espace Balcon.*). The care sheets it
  drafts (common name, pruning and cutting advice, notes) are in the language of the interface. The Latin name stays
  the pivot.
- The species search of the forms (*Add a plant*, *New species*) finds a French name Trefle does not know: when Trefle
  finds nothing, one short call to the LLM gives its Latin names, cached per name, and Trefle is searched with them.
  In demo mode, these names come from [`fixtures/llm/latin-names.json`](fixtures/llm/latin-names.json): try `rosier`.
- The reminders, their notifications and the frost toasts are written in the language of the interface.

The frost alert fires below the hardiness of the plant plus 2 °C (0 °C for a frost-sensitive plant). A panicled
hydrangea is hardy to about -25 °C, so the recorded cold snap (down to -12.4 °C) warns about the lemon tree of
`castor demo:reset`, not about it.

### The public pages

<https://hop-garden.test> is the public page: what Hop Garden takes care of, in English or in French, with its own top
bar (the language, *Log in*, *Join the waiting list*, or *Open my garden* once logged in). The legal notice
(`/legal-notice`), the terms of sale (`/terms`) and the privacy policy (`/privacy`) are linked from the footer of every
page; their blanks, marked *To be completed*, wait for the information of the publisher. The application starts at
`/assistant`, the chat of the last garden of the member.

### Gardens

Each garden has its members, who share its care sheets, its spaces, its plants and its reminders: nobody else sees any of
them. In `castor demo:reset`, Alice and Bob share "The allotment" (Alice is its owner), Bob has "The orchard" too, and
Carol has "Carol's balcony".

- Every page of a garden is under its URL, by the slug of its name: `/g/the-allotment/assistant`, `/g/the-allotment/garden`,
  `/g/the-allotment/species/rosa-canina`, `/g/the-allotment/garden/rose-by-the-gate`, `/g/the-allotment/spaces/terrace`,
  `/g/the-allotment/notifications`, `/g/the-allotment/settings`. A garden takes its slug from its name, unique in the
  whole site; a species from its Latin name, a plant and a space from their name, unique in their garden, suffixed when
  taken (`rose-2`). Renaming keeps the URL. A garden of others answers 404, and so does a slug of another garden in the
  URL of one's own.
- Out of a garden (the account, the wizard), the menu leads to the last one; so do the links of before the gardens were
  in the URLs (`/assistant`, `/garden`, `/plants`, `/spaces`, `/notifications`). Two tabs on two gardens each have their
  live chat.
- A new member makes their first garden in a small wizard, <https://hop-garden.test/gardens/new>: its name and its city,
  found by the [geocoding of Open-Meteo](https://open-meteo.com/en/docs/geocoding-api) (a city with homonyms offers them,
  one to pick). Its position gives the frost forecast, its timezone the hour of the reminders: every day from 7:00, its
  time.
- With several gardens, the menu switches between them, to the same page of the other one when it has it (its plants,
  its species...), else to its assistant; the chat has a conversation per garden. Its owner renames or moves a garden in
  its settings.
- Its name in the menu opens its members, <https://hop-garden.test/g/the-allotment/members>: each member may leave it
  (its owner leaves it to the oldest of the others, its only member does not), its owner removes a member and invites,
  see [Invitations](#invitations). Who goes loses their conversations with the assistant and their notifications of
  it; what they added stays.
- Every member gets the notifications of the garden, in their language, and the frost toast, live.
- The assistant has a quota per garden and per day (its runs, and the Latin names of the species search): spent, the
  chat says so. So have the invitations it sends by email, 20 a day.
- Deleting an account deletes the gardens its owner was the only member of, with their photos and conversations; a
  shared garden stays with the others, the oldest of them its owner.

### Invitations

The owner of a garden invites by email, on the page of its members, as a member (one owner per garden).

- The email goes in the language of the account of the address if it has one, else in the one of the owner. Its link,
  `/invitations/{token}`, works 14 days, once: the page shows it too, with a *Copy* button, to send another way.
- An invitation waiting is sent again, its link copied again, or revoked. Only the hash of its token is stored, so every
  new link replaces the one before. Inviting an address again renews its invitation: one per address in a garden.
- The link presents the garden and who invites. A visitor creates an account there, or logs in from it and comes back to
  join. The invitation lets a new account in without the waiting list: at once with the address invited, which it
  verifies, once its email is verified with another one. Logged in with another account, they join with it, or log out
  to change. Whoever has the link may use it: the owner, who sees it, could as well.
- The one who invited gets a notification, and its toast, when somebody joins. An invitation expired, revoked or used,
  or of a garden deleted, says so, and nothing of its garden.
- 30 days after it ends (expired, accepted or revoked), an invitation is deleted, with its address and the notification
  of the member who joined with it: every day at 3:00 UTC, by the worker.
- An account still on the waiting list waits for the administrator: its login is refused until then, but the one of the
  link of an invitation (`/invitations/{token}/login`). It lets the account in as a new one would (at once with the
  address invited, once verified with another one), and it joins the garden: one account per invitation.

In `castor demo:reset`, Alice has invited `erin@hop-garden.test`, who has no account: the task prints the link.

Before the gardens, a database had none, and `castor migrate` refuses it: the migration that requires a garden for
every row (`Version20261123120000`, the last one) names the task that gives them one, and nothing is migrated. `castor
app:garden:adopt-orphans --email=you@example.com --name="My garden" --city=Lyon` runs the migrations up to it (the
gardens, the UUIDs, the slugs), gives every row to a new garden of that member, then runs it. The member is an approved
account: from a database of before the accounts too, run the task once (it migrates, then asks for them), create them
with `castor app:user:create ... --approved`, and run it again.

The ids became UUIDs v7 (`Version20261123100000`): the migration gives each row one, in the order of the ids, and the
foreign keys and the conversations of the chat follow. A member logged in before it is logged out once; a link of an
email to verify an address, sent before, asks for a new password, which verifies it too; the URLs of before with an id
(`/garden/12`) are gone, the ones without (`/garden`) lead to the last garden.

### Accounts and the waiting list

Hop Garden opens to a few gardeners at a time. Everything but the public pages, the login, the registration, the
password reset and the link of an invitation needs an approved account.

1. *Sign up*: email, name, password, and the terms of sale. A link to verify the email follows, valid 48 hours. The
   page says the same whether the address has an account or not: the email tells its owner.
2. Once verified, the account waits on the list. The administrator approves or refuses it in the back-office,
   <https://hop-garden.test/admin>, and the member gets an email in their language: *You can come in*. An invitation
   to a garden skips the list: see [Invitations](#invitations).
3. *Log in*, with *Remember me* for 30 days. After 5 failed attempts, the login waits 15 minutes. *Forgot your
   password?* sends a link valid one hour, which verifies the email too.
4. *My account*: the name and the language, the email (the new one verified first), the password (the current one
   required), and the deletion of the account.

In dev, every email lands in [Mailpit](https://mailpit.axllent.org), <https://mail.hop-garden.test>. The demo accounts
of `castor demo:reset`, all with the password `hop-garden-demo`:

| Email | |
|---|---|
| `admin@hop-garden.test` | The administrator, of the back-office too |
| `alice@hop-garden.test` | A member, in English, owner of "The allotment" |
| `bob@hop-garden.test` | A member, in French, who shares "The allotment" and owns "The orchard" |
| `carol@hop-garden.test` | A member, in English, owner of "Carol's balcony" |
| `dave@hop-garden.test` | On the waiting list, in French: approve him, then he makes his garden |

Erin has no account: Alice invited `erin@hop-garden.test`, and `castor demo:reset` prints the link of the invitation.

On a new installation, create the administrator:

```bash
castor app:user:create --email=you@example.com --name=You --password='a long password' --admin
```

## Architecture

One Symfony 8.1 application is both the MCP server and the web UI whose agent consumes it.

```mermaid
flowchart LR
    browser["Browser<br/>Twig + Stimulus"]
    subgraph frontend["FrankenPHP"]
        web["Symfony<br/>web UI"]
        hub(["Mercure hub"])
        mcp["MCP server<br/>/_mcp · /_mcp/agent"]
    end
    subgraph worker["Messenger worker"]
        agent["Symfony AI agent"]
        client["MCP client"]
        scheduler["Scheduler<br/>reminders at 7:00"]
    end
    postgres[("PostgreSQL")]
    llm["LLM<br/>Anthropic / OpenAI"]
    apis["Trefle · Open-Meteo"]
    external["Claude Desktop<br/>Claude Code · Cursor"]

    browser -- "POST /chat" --> web
    web -- "RunAgent message" --> postgres
    postgres -- "LISTEN/NOTIFY" --> agent
    agent <--> llm
    agent --> client
    client -- "HTTP" --> mcp
    mcp --> apis
    mcp --> postgres
    agent -- "tokens, tool calls,<br/>draft card" --> hub
    scheduler -- "frost and calendar<br/>toasts" --> hub
    hub -- "SSE" --> browser
    external -- "HTTP or STDIO" --> mcp
```

- Sending a message dispatches a Messenger message, for the member and their garden. The worker runs the agent and
  pushes Turbo Streams (the tokens, the tool calls, then the draft card as a frame the browser loads) through the
  Mercure hub embedded in FrankenPHP, rendered with Twig from the same components as the pages, without polling
  (same model as [lyrixx/async-messenger-mercure](https://github.com/lyrixx/async-messenger-mercure)). Every update is
  private: the page of a member subscribes with a cookie that grants their topics, and those of each of their gardens.
- The agent ([Symfony AI](https://symfony.com/doc/current/ai/index.html)) gets its tools from the
  [MCP bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html) client, connected to this application's
  own MCP server: no PHP service is called directly. Each request carries a token signed for the run: the tools answer
  in the garden of whoever wrote the message. The conversation is stored with the Doctrine message store, one per
  session and garden.
- Reminders: the Scheduler dispatches a check every hour, and each garden is checked once a day from 7:00 in its
  timezone, with the forecast at its position (cached by position, rounded); notifications go through the Notifier to
  an in-app channel and a toast pushed with Mercure, one per member, in their language. The *Notifications* page lists them under the reminders coming up and the
  frost forecast with the plants at risk: by hand, what `upcoming_reminders` and `get_frost_forecast` give the agent.
- Everything reads the time from `ClockInterface`: `app:reminders:run --date=2026-12-01` simulates a day.
- Accounts: Symfony Security (`form_login`, remember me, `login_throttling`), denied by default; a `UserChecker` lets
  approved members only in, and tells the others why. The links of the emails are signed and dated (`UriSigner`), a
  password reset token, as the one of an invitation, is stored hashed. The back-office is [EasyAdmin](https://symfony.com/bundles/EasyAdminBundle),
  the emails are Twig templates sent by Symfony Mailer.
- The stack is based on [jolicode/docker-starter](https://github.com/jolicode/docker-starter): FrankenPHP,
  PostgreSQL, a Messenger worker, a Traefik router for `hop-garden.test` in HTTPS, and a builder container for
  the CLI.

## MCP server

| Endpoint | For | Validates plants |
|---|---|---|
| STDIO with `castor mcp:stdio --user=<email> [--garden=<slug>]` | External clients, as a member, in a garden of theirs | Yes, with `confirm_plant` (the care sheet only) |
| `https://hop-garden.test/_mcp` | External clients: a 401 until OAuth comes | — |
| `http://frontend/_mcp/agent` | The chat agent of the web UI, with the token of its run (401 without) | No |

The tools see one garden: the one of the member over STDIO (`--garden=<slug>` for another of theirs, as in its URLs), the
one of the message for the agent. Their ids are UUIDs, in and out; their results give the readable URL of what they
return too (`url`, `validation_url`: `https://hop-garden.test/g/the-allotment/species/rosa-canina`).

### Tools

| Tool | What it does |
|---|---|
| `search_plant(query)` | Identifies a plant with Trefle: Trefle id, scientific name, common name, family, image. |
| `get_frost_forecast(days = 3)` | Daily minimum temperatures at the garden, from Open-Meteo. |
| `propose_plant(common_name, scientific_name, family, care_sheet, …)` | Creates a **draft** plant and returns its id and the URL of its card. The schema of `care_sheet` imposes every field, its confidence and its sources. |
| `list_collection()` | The validated plants of the garden (one care sheet per species) and their enabled reminders. |
| `get_plant(id)` | A plant (its UUID), its care sheet, the confidence of each value, its sources. |
| `enable_reminder(plant_id, type)` / `disable_reminder(plant_id, type)` | `pruning`, `deadheading`, `cutting` or `frost`. Refused for a draft. |
| `upcoming_reminders(days = 30)` | The calendar reminders due soon, and the known frost risk. |
| `list_spaces()` | The spaces of the garden, with their exposure, their soil and their plants. |
| `propose_space(name, exposure?, soil?)` | Writes nothing: returns the card of a new space, and its `validation_url`, where the user completes and creates it. |
| `propose_planting(plant_id, name, space_id?)` | Writes nothing: returns the *Add to my garden* card of a plant of a validated species, and its `validation_url`. |
| `confirm_plant(id)` | External clients only: validates a draft care sheet, once the user explicitly confirmed it. It adds no plant to the garden. |
| `offer_choices(question, options)` | The chat agent only: a question with one button per option. |

### Resources and prompt

- `hopgarden://collection`: the validated plants of the garden, in Markdown.
- `hopgarden://plants/{id}`: the care sheet of a plant, in Markdown.
- `add_plant(name)`: the "identify, complete, propose" scenario.

Run `castor builder -- bin/console debug:mcp` to list them with their input schemas.

### Connect Claude Code, Claude Desktop or Cursor

Start the stack first (`castor start`).

Until OAuth, over STDIO, as a member of the demo:

```bash
claude mcp add hop-garden -- castor --castor-file /path/to/hop-garden/castor.php mcp:stdio --user=alice@hop-garden.test
```

`https://hop-garden.test/_mcp` answers 401 to any client until then: the connection in one click (OAuth) is the next
step.

Claude Desktop or Cursor (`claude_desktop_config.json`, `.cursor/mcp.json`):

```json
{
    "mcpServers": {
        "hop-garden": {
            "command": "castor",
            "args": ["--castor-file", "/path/to/hop-garden/castor.php", "mcp:stdio", "--user=alice@hop-garden.test"]
        }
    }
}
```

Then ask: *"Use the add_plant prompt with lavender."* The description of `confirm_plant` tells the model to wait for
your explicit confirmation before validating the draft.

## Configuration

Set these in `.env.local`, then restart the workers (`castor stop-workers && castor start-workers`): the worker
reads the environment when it starts.

| Variable | Default | |
|---|---|---|
| `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` | | Key of the LLM provider. |
| `AI_PLATFORM` / `AI_MODEL` | `anthropic` / `claude-sonnet-5-5` | Provider and model of the chat agent. |
| `APP_DEMO` | `1` | Recorded responses instead of Trefle and Open-Meteo; `0` to use the real services. |
| `TREFLE_TOKEN` | | Free token from [trefle.io](https://trefle.io), when `APP_DEMO=0`. |
| `MAILER_DSN` | `null://null`, Mailpit in dev | How the emails of the accounts leave: an SMTP server or a [provider](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport). |
| `MAILER_FROM` | `Hop Garden <hello@hop-garden.test>` | Their sender. |

The location of the frost forecast and the timezone of the reminders are those of each garden, picked by its owner:
`GARDEN_LATITUDE`, `GARDEN_LONGITUDE` and `GARDEN_TIMEZONE` are gone. The answers of the external APIs are cached: 7
days for plants, 30 days for places, 1 hour (and the current day) for forecasts, by position rounded to a tenth of a
degree.

## Development

```bash
castor                      # list the tasks
castor builder              # shell in the builder container (composer, bin/console…)
castor logs                 # logs of the stack
castor pg                   # psql
castor qa                   # PHP-CS-Fixer, PHPStan (level 9), Twig-CS-Fixer, PHPUnit
castor stop
```

The demo video is made with [Remotion](https://www.remotion.dev): `castor video:capture` films the scenario in the
running stack (with your LLM key) and `castor video:render` adds the captions, the architecture and the credits, in
`video/out/hop-garden.mp4`. Both need Node.js and ffmpeg on the host.

The tests call every MCP tool with the MCP SDK client over HTTP, run the whole chat flow (worker, agent, MCP
client, MCP server) with a scripted model, check the reminders with a mock clock, and the validation controller.
See [AGENTS.md](AGENTS.md) for the conventions.

The demo data is a [Foundry](https://github.com/zenstruck/foundry) story, `src/Story/DemoStory.php`, built with the
factories of `src/Factory/` that the tests use too. `castor demo:reset` loads it with `foundry:load-fixtures demo`, in
a database recreated from the migrations (the Messenger worker is stopped meanwhile).

## Production image

One image, `ghcr.io/lyrixx/hop-garden/app`, built from the "Production stages" of
`infrastructure/docker/services/php/Dockerfile`: FrankenPHP serves the application, the Mercure hub and the MCP
server on port 8080, in HTTP, and the same image runs the migrations and the worker. Every push to `main` builds it
and pushes it, tagged with the short commit sha and `latest` (`.github/workflows/build-push.yml`). A push to another
branch gets a preview image instead, tagged `pr-<branch>-<short sha>` and `pr-<branch>`, never `latest`.

```bash
castor start -c prod        # the image in a minimal stack, in demo mode: http://127.0.0.1:8000
castor stop -c prod
castor docker:push -c prod --tag="$(git rev-parse --short=7 HEAD)" --tag=latest   # by hand, after a docker login to ghcr.io
```

Besides the variables of [Configuration](#configuration), the image needs `APP_SECRET` (it signs the tokens of the agent
runs too), `DATABASE_URL`, `DEFAULT_URI` (the Mercure topics live under it), the Mercure ones (`MERCURE_URL`,
`MERCURE_PUBLIC_URL`, its audience too, `MERCURE_JWT_ISSUER`, `MERCURE_PUBLISHER_JWT_KEY`, and
`MERCURE_SUBSCRIBER_JWT_KEY`, another key: the hub has no anonymous subscriber), `MCP_AGENT_URL`, `MCP_ALLOWED_HOSTS`
(the hosts of the MCP server: its domain, and the one the worker reaches it by), the mailer ones (`MAILER_DSN`: in
production, Mailgun, `mailgun+api://KEY:DOMAIN@default?region=eu`, `region` being that of the sending domain in Mailgun,
whose SPF and DKIM are declared in its DNS; `MAILER_FROM`, an address of that domain), and those of the errors
(`SENTRY_DSN`, `SENTRY_ENVIRONMENT`, see [Errors (Sentry)](#errors-sentry)). One volume, shared by the web and the
worker: `var/share/` (`APP_SHARE_DIR`: the sessions, so the conversation of the chat survives a deployment, the
`cache.app` pool with the quotas and the login throttling, and the photos of the plantings in
`var/share/prod/uploads/plantings` (the share directory of the environment), served by the application to the members of
their garden only). The probes (the readiness and liveness of an orchestrator, the `HEALTHCHECK` of the image) go to
`/healthz`: a 200 in `text/plain` as long as PHP answers, without session, cookie nor database, where a page would leave
a session file in `var/share/` at every probe. The first administrator, once the migrations ran:
`bin/console app:user:create --email=... --name=... --password=... --admin`.

From a database of before the gardens: `bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261123110000'`,
`bin/console app:garden:adopt-orphans --email=... --name=... --city=...`, then `bin/console doctrine:migrations:migrate`.
The photos used to be public, in a volume on `public/uploads/`: mount it there once more for the deployment that makes
them private, run `bin/console app:plantings:move-photos`, then drop it.

### Errors (Sentry)

The errors of the production and of the preproduction go to Sentry once `SENTRY_DSN` is given (empty, the default:
nothing leaves), with `SENTRY_ENVIRONMENT=preprod` on the preproduction (`prod` by default). The release is the short
commit sha, baked into the image (`SENTRY_RELEASE`). An issue is whatever is logged at the `error` level or above (an
uncaught exception, a failing command, `$logger->error()`), without the HTTP 4xx nor the unknown commands (logged as
`notice`, even `bin/console messenger`, which lists the namespace), and each message of the worker that fails (once:
there is no retry). It carries the logs from the `info` level that came before it, without the channels that quote
URLs, emails or tokens (`http_client`, `request`, `security`, `mcp`), and the id of the account logged in
(`user:<uuid>`), never its email nor its IP. No body of a request or a response (the messages of the chat, the
requests to the LLM and its answers), no query string, no argument of the functions of a stack trace, and a command
by its name only, without its options (`app:user:create --email --password`). The URL keeps its path: an error on the
link of an invitation or of a password reset carries its token. One request or command out of ten is measured too
(performance), but for these links. Configuration: `config/packages/sentry.yaml` and `monolog.yaml`.

## Credits and licenses

- Plant data: [Trefle](https://trefle.io), under its own terms.
- [Weather data by Open-Meteo.com](https://open-meteo.com/), under
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
- Pruning, deadheading and cutting periods come from the LLM and are reviewed by you. The SNHF and RHS sites are
  not scraped: each card only links to a search on them.
- Built with [Symfony](https://symfony.com), [Symfony AI](https://github.com/symfony/ai), the
  [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk), [FrankenPHP](https://frankenphp.dev) and
  [Castor](https://castor.jolicode.com).

Hop Garden is released under the [MIT license](LICENSE).

## Known limits

- **LLM-filled care data is reviewed by a human before being saved.** Nothing joins the garden without your
  tap, but the values you validate are only as good as your review.
- The care data assumes a temperate Western European climate.
- Gardens shared by their members, but no invitation yet: `castor demo:reset` gives the demo its members. The external
  MCP clients connect over STDIO only, until OAuth.
- The responses in `fixtures/http/` are hand-written samples in the format of each API, not real records; their
  Trefle ids are made up. Set `APP_DEMO=0` and `TREFLE_TOKEN` in `.env.local` to use the real services.
- A notification keeps the language it was sent in, and the data of `castor demo:reset` is in English.
- E-mail notifications are not implemented: notifications stay in the application.
