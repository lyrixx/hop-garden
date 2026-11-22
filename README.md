# Hop Garden

Hop Garden manages the plants of your garden with an AI agent. Name a plant in the chat: the agent identifies it,
drafts its care sheet with a confidence level for every value, and you validate it. Then it reminds you when to
prune, deadhead and take cuttings, and warns you before frost.

![Adding a hydrangea, validating its card, then a frost alert](media/hop-garden.gif)

<sub>Recorded in demo mode, with a scripted model standing in for the LLM (no API key where it was recorded).</sub>

**Symfony AI & MCP Hackathon** · Tags: `MCP` `Agent` `Symfony AI`

- **The human validates, always.** The agent only proposes drafts. A plant joins the garden when you click
  *Validate* on its card, after reviewing the highlighted values. The agent of the web UI has no tool to validate.
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

Open <https://hop-garden.test> and type `hortensia`.

That's all: the demo mode is on by default, so Trefle and Open-Meteo answer from the responses
stored in [`fixtures/http/`](fixtures/http/). Only the LLM key is needed.

To use OpenAI instead of Anthropic, put this in `.env.local`:

```bash
AI_PLATFORM=openai
AI_MODEL=gpt-5.4-mini
OPENAI_API_KEY=sk-...
```

### The demo

1. Type `hortensia`. The agent calls `search_plant` and asks which hydrangea you have.
2. Answer `paniculata`. It completes the sheet with its own knowledge, then calls `propose_plant`: the draft card
   shows up, its uncertain values highlighted.
3. Click *Edit* to correct a value, then *Save*: the card comes back in the conversation. Click *Validate*: the card
   says *In your garden* and shows its reminder switches; turn on pruning and frost.
4. *See my plant* opens your hydrangea in *My garden*: the plant you grow (where it grows, the care it got, the
   recommendations), apart from its species (the card and its reminders, on the page of the species). Each links to
   the other. The page of your plant asks where it grows, until you choose its space with *Edit garden info*.
5. *My spaces*: describe the spots of your garden (balcony, north bed…) with their exposure and soil; the plants of a
   space share them and are neighbours. The recommendations read the exposure of the space.
6. Run `castor demo:frost`: the reminder check runs against the recorded cold snap, and a frost toast appears live,
   pushed by Mercure.

The frost alert fires below the hardiness of the plant plus 2 °C (0 °C for a frost-sensitive plant). A panicled
hydrangea is hardy to about -25 °C, so the recorded cold snap (down to -12.4 °C) warns about the lemon tree of
`castor demo:reset`, not about it.

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
  channel (the *Notifications* page) and a toast pushed with Mercure.
- Everything reads the time from `ClockInterface`: `app:reminders:run --date=2026-12-01` simulates a day.
- The stack is based on [jolicode/docker-starter](https://github.com/jolicode/docker-starter): FrankenPHP,
  PostgreSQL, a Messenger worker, a Traefik router for `hop-garden.test` in HTTPS, and a builder container for
  the CLI.

## MCP server

| Endpoint | For | Validates plants |
|---|---|---|
| `https://hop-garden.test/_mcp`, or STDIO with `castor mcp:stdio` | External clients | Yes, with `confirm_plant` |
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
| `confirm_plant(id)` | External clients only: validates a draft, once the user explicitly confirmed it. |

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
  validation, but the values you validate are only as good as your review.
- The care data assumes a temperate Western European climate.
- No authentication: Hop Garden is a single-user application meant to run locally. The MCP endpoints and the
  Mercure topics are open to whoever reaches the server.
- The responses in `fixtures/http/` are hand-written samples in the format of each API, not real records; their
  Trefle ids are made up. Set `APP_DEMO=0` and `TREFLE_TOKEN` in `.env.local` to use the real services.
- One garden location for the frost forecast.
- E-mail notifications are not implemented: notifications stay in the application.
