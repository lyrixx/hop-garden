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
castor demo:reset   # optional: a rose and a lemon tree, with their reminders, and 3 spaces
```

Open <https://hop-garden.test/assistant> and type `hortensia`.

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
5. *Species* (`/plants`): every care sheet, its plate and my plants of it; *New species* without the assistant.
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

Hop Garden speaks English and French. The language is a setting of the application, not of the URL: with a browser in
French, the pages stay in English until you choose, under a band in French, *Passer en français* or *Rester en
anglais*. The band does not come back, and the footer switches the language later. `castor demo:reset` forgets it.

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
bar (the language, *Log in*, *Join the waiting list*: both lead nowhere until the accounts exist). The legal notice
(`/legal-notice`), the terms of sale (`/terms`) and the privacy policy (`/privacy`) are linked from the footer of every
page; their blanks, marked *To be completed*, wait for the information of the publisher. The application starts at
`/assistant`, the chat.

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

- Sending a message dispatches a Messenger message. The worker runs the agent and pushes Turbo Streams (the tokens,
  the tool calls, then the draft card as a frame the browser loads) through the Mercure hub embedded in FrankenPHP,
  rendered with Twig from the same components as the pages, without polling
  (same model as [lyrixx/async-messenger-mercure](https://github.com/lyrixx/async-messenger-mercure)).
- The agent ([Symfony AI](https://symfony.com/doc/current/ai/index.html)) gets its tools from the
  [MCP bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html) client, connected to this application's
  own MCP server: no PHP service is called directly. The conversation is stored with the Doctrine message store,
  one per session.
- Reminders: the Scheduler dispatches a check every day at 7:00; notifications go through the Notifier to an in-app
  channel and a toast pushed with Mercure. The *Notifications* page lists them under the reminders coming up and the
  frost forecast with the plants at risk: by hand, what `upcoming_reminders` and `get_frost_forecast` give the agent.
- Everything reads the time from `ClockInterface`: `app:reminders:run --date=2026-12-01` simulates a day.
- The stack is based on [jolicode/docker-starter](https://github.com/jolicode/docker-starter): FrankenPHP,
  PostgreSQL, a Messenger worker, a Traefik router for `hop-garden.test` in HTTPS, and a builder container for
  the CLI.

## MCP server

| Endpoint | For | Validates plants |
|---|---|---|
| `https://hop-garden.test/_mcp`, or STDIO with `castor mcp:stdio` | External clients | Yes, with `confirm_plant` (the care sheet only) |
| `http://frontend/_mcp/agent` | The chat agent of the web UI | No |

### Tools

| Tool | What it does |
|---|---|
| `search_plant(query)` | Identifies a plant with Trefle: Trefle id, scientific name, common name, family, image. |
| `get_frost_forecast(days = 3)` | Daily minimum temperatures at the garden, from Open-Meteo. |
| `propose_plant(common_name, scientific_name, family, care_sheet, …)` | Creates a **draft** plant and returns its id and the URL of its card. The schema of `care_sheet` imposes every field, its confidence and its sources. |
| `list_collection()` | The validated plants of the garden (one care sheet per species) and their enabled reminders. |
| `get_plant(id)` | A plant, its care sheet, the confidence of each value, its sources. |
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

Claude Code, over HTTP:

```bash
claude mcp add --transport http hop-garden https://hop-garden.test/_mcp
```

If the certificate of `hop-garden.test` is not trusted by Node.js (no [mkcert](https://github.com/FiloSottile/mkcert)),
start Claude Code with `NODE_EXTRA_CA_CERTS` pointing to the CA, or use STDIO:

```bash
claude mcp add hop-garden -- castor --castor-file /path/to/hop-garden/castor.php mcp:stdio
```

Claude Desktop or Cursor (`claude_desktop_config.json`, `.cursor/mcp.json`):

```json
{
    "mcpServers": {
        "hop-garden": {
            "command": "castor",
            "args": ["--castor-file", "/path/to/hop-garden/castor.php", "mcp:stdio"]
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
| `GARDEN_LATITUDE` / `GARDEN_LONGITUDE` | `46.6` / `2.4` (center of France) | Location of the frost forecast. |
| `GARDEN_TIMEZONE` | `Europe/Paris` | Timezone of the 7:00 check. |

The answers of the external APIs are cached: 7 days for plants, 1 hour (and the current day) for forecasts.

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
and pushes it, tagged with the short commit sha and `latest` (`.github/workflows/build-push.yml`).

```bash
castor start -c prod        # the image in a minimal stack, in demo mode: http://127.0.0.1:8000
castor stop -c prod
castor docker:push -c prod --tag="$(git rev-parse --short=7 HEAD)" --tag=latest   # by hand, after a docker login to ghcr.io
```

Besides the variables of [Configuration](#configuration), the image needs `APP_SECRET`, `DATABASE_URL`,
`DEFAULT_URI`, the Mercure ones (`MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_ISSUER`,
`MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`), `MCP_AGENT_URL` and `MCP_ALLOWED_HOSTS` (the hosts
of the MCP server: its domain, and the one the worker reaches it by). Two volumes, shared by the web and the
worker: `public/uploads/` (the photos of the plantings) and `var/share/` (`APP_SHARE_DIR`: the sessions, so the
conversation of the chat survives a deployment, and the `cache.app` pool).

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
- No authentication: Hop Garden is a single-user application meant to run locally. The MCP endpoints and the
  Mercure topics are open to whoever reaches the server.
- The responses in `fixtures/http/` are hand-written samples in the format of each API, not real records; their
  Trefle ids are made up. Set `APP_DEMO=0` and `TREFLE_TOKEN` in `.env.local` to use the real services.
- One garden location for the frost forecast.
- One language for the whole application, as there are no accounts. A notification keeps the language it was sent in,
  and the data of `castor demo:reset` is in English.
- E-mail notifications are not implemented: notifications stay in the application.
