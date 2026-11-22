// The script of the video: every text and duration, apart from the design (src/scenes, src/components).
// The captions of the demo appear when their step happens in the capture (see capture.mjs for the marker names).

export type DemoCaption = { marker: string; delay?: number; title: string; subtitle?: string };

export type DemoStep = {
    id: string;
    step: string;
    // The part of the capture shown, between two markers (seconds added to each)
    from: { marker: string; delay: number };
    to: { marker: string; delay: number };
    // Longest on screen: a slow LLM is sped up rather than making the video longer
    maxSeconds: number;
    captions: DemoCaption[];
    terminal?: { command: string; enterAt: string };
};

export const script = {
    intro: {
        seconds: 4.5,
        title: 'Hop Garden',
        tagline: 'Your garden, managed with an AI agent that talks MCP',
        tags: ['MCP', 'Agent', 'Symfony AI'],
        event: 'Symfony AI & MCP Hackathon',
    },

    principle: {
        seconds: 4,
        lines: ['Name a plant.', 'The agent drafts its care sheet.'],
        punchline: 'You validate. Always.',
    },

    demo: [
        {
            id: 'chat',
            step: '1',
            from: { marker: 'type_hortensia', delay: -0.8 },
            to: { marker: 'question_done', delay: 2.2 },
            maxSeconds: 13,
            captions: [
                { marker: 'type_hortensia', title: 'Name a plant' },
                { marker: 'tool_search', title: 'The agent calls the app’s own MCP server', subtitle: 'search_plant → Trefle' },
                { marker: 'question', title: 'Several species match: it asks which one' },
            ],
        },
        {
            id: 'draft',
            step: '2',
            from: { marker: 'type_paniculata', delay: -0.5 },
            to: { marker: 'validate', delay: -0.2 },
            maxSeconds: 17,
            captions: [
                { marker: 'type_paniculata', title: 'It drafts the care sheet' },
                { marker: 'tool_propose', title: 'Its own knowledge completes the sheet', subtitle: 'propose_plant → a draft, nothing saved yet' },
                { marker: 'card', title: 'Every value carries a confidence', subtitle: 'The uncertain ones are highlighted for review' },
            ],
        },
        {
            id: 'validate',
            step: '3',
            from: { marker: 'validate', delay: -0.2 },
            to: { marker: 'reminder_frost', delay: 0.6 },
            maxSeconds: 11,
            captions: [
                { marker: 'validate', title: 'You validate. Always.', subtitle: 'The agent has no tool to save a plant' },
                { marker: 'reminders', title: 'Pick its reminders', subtitle: 'Pruning, deadheading, cuttings, frost' },
            ],
        },
        {
            id: 'frost',
            step: '4',
            from: { marker: 'reminder_frost', delay: 0.6 },
            to: { marker: 'end', delay: 0 },
            maxSeconds: 12,
            captions: [
                { marker: 'reminder_frost', delay: 0.6, title: 'Frost alert, pushed live', subtitle: 'Scheduler at 7:00 · Open-Meteo forecast · Mercure' },
            ],
            terminal: { command: 'castor demo:frost', enterAt: 'frost_command' },
        },
    ] satisfies DemoStep[],

    architecture: {
        seconds: 10.5,
        title: 'One Symfony app, MCP server and MCP client',
        subtitle: 'The agent never calls a PHP service directly: it goes through MCP, like any client',
    },

    clients: {
        seconds: 6.5,
        title: 'The same MCP server, in your own client',
        subtitle: 'Claude Code, Claude Desktop, Cursor: over HTTP, or STDIO with castor mcp:stdio',
        command: 'claude mcp add --transport http hop-garden https://hop-garden.test/_mcp',
        capabilities: ['search_plant', 'get_frost_forecast', 'propose_plant', 'confirm_plant', 'list_collection', 'get_plant', 'enable_reminder', 'disable_reminder', 'upcoming_reminders'],
        resources: ['hopgarden://collection', 'hopgarden://plants/{id}', 'prompt: add_plant'],
        note: 'confirm_plant is only offered to external clients, after an explicit confirmation of the user.',
    },

    outro: {
        seconds: 6,
        title: 'Hop Garden',
        repository: 'github.com/lyrixx/hop-garden',
        tagline: 'git clone · castor start · a demo in 5 minutes',
        credits: [
            'Plant data: Trefle · Weather data by Open-Meteo.com (CC BY 4.0)',
            'Symfony 8.1 · Symfony AI · MCP bundle · FrankenPHP · Mercure · MIT license',
        ],
    },
};
