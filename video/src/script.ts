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
    // Out of the way once its output is read, from `leaveAt`: it hides the bottom of the page
    terminal?: { command: string; enterAt: string; leaveAt: { marker: string; delay: number } };
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
        lines: ['Say what you planted.', 'The agent prepares every card.'],
        punchline: 'You validate. Always.',
    },

    // At most 55 s with the maxSeconds below, and 31.5 s for the other scenes: the video stays under 90 s
    demo: [
        {
            id: 'ask',
            step: '1',
            from: { marker: 'type', delay: -0.6 },
            to: { marker: 'tap_species', delay: -0.2 },
            maxSeconds: 11.5,
            captions: [
                { marker: 'type', title: 'Say what you planted', subtitle: 'In plain words, in any language' },
                { marker: 'tool_first', title: 'The agent calls the app’s own MCP server', subtitle: 'list_collection: no hydrangea yet · search_plant → Trefle' },
                { marker: 'choices', title: 'Several species match: tap yours', subtitle: 'offer_choices, one button per candidate' },
            ],
        },
        {
            id: 'draft',
            step: '2',
            from: { marker: 'tap_species', delay: -0.2 },
            to: { marker: 'validate', delay: -0.2 },
            maxSeconds: 11,
            captions: [
                { marker: 'tap_species', title: 'One tap, and it drafts the care sheet', subtitle: 'Trefle and its own knowledge · propose_plant, a draft: nothing saved yet' },
                { marker: 'review', title: 'Every value carries a confidence', subtitle: 'The uncertain ones are highlighted for review' },
            ],
        },
        {
            id: 'validate',
            step: '3',
            from: { marker: 'validate', delay: -0.2 },
            to: { marker: 'add', delay: -0.2 },
            maxSeconds: 8,
            captions: [
                { marker: 'validate', title: 'You validate. Always.', subtitle: 'The agent only proposes: each card waits for your tap' },
                { marker: 'planting_card', title: 'It knows your spaces: the terrace', subtitle: 'propose_planting → an “Add to my garden” card' },
            ],
        },
        {
            id: 'add',
            step: '4',
            from: { marker: 'add', delay: -0.2 },
            to: { marker: 'see_plant', delay: -0.2 },
            maxSeconds: 11,
            captions: [
                { marker: 'add', title: 'One more tap: it grows on the terrace', subtitle: 'Added, with a link to the plant' },
                { marker: 'reminders', title: 'Switch on its reminders', subtitle: 'Frost and pruning, on the validated care sheet' },
            ],
        },
        {
            id: 'plant',
            step: '5',
            from: { marker: 'see_plant', delay: -0.2 },
            to: { marker: 'frost_command', delay: -1.4 },
            maxSeconds: 5.5,
            captions: [
                { marker: 'see_plant', title: 'The same garden, without the agent', subtitle: 'My plant: its space, its neighbours, its recommendations' },
            ],
        },
        {
            id: 'frost',
            step: '6',
            from: { marker: 'frost_command', delay: -1.4 },
            to: { marker: 'end', delay: 0 },
            maxSeconds: 8,
            captions: [
                { marker: 'frost_command', delay: -1.4, title: 'Frost alert, pushed live', subtitle: 'For the lemon tree on the terrace · Scheduler at 7:00 · Open-Meteo · Mercure' },
            ],
            terminal: { command: 'castor demo:frost', enterAt: 'frost_command', leaveAt: { marker: 'toast', delay: 2 } },
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
        capabilities: [
            'search_plant',
            'propose_plant',
            'confirm_plant',
            'list_collection',
            'get_plant',
            'list_spaces',
            'propose_space',
            'propose_planting',
            'enable_reminder',
            'disable_reminder',
            'upcoming_reminders',
            'get_frost_forecast',
        ],
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
