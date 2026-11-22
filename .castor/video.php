<?php

namespace video;

use Castor\Attribute\AsTask;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\check;
use function Castor\context;
use function Castor\io;
use function Castor\run;
use function Castor\variable;

// The video tooling (Remotion, Playwright) runs on the host, as Node.js tools, like the media of
// lyrixx/async-messenger-mercure: nothing of it reaches the application.

#[AsTask(description: 'Installs the video tooling on the host: Remotion, Playwright and its Chromium (one-time setup)')]
function install(): void
{
    io()->title('Installing the video tooling');

    check_prerequisites();

    run(['npm', 'ci'], context: video_context());
    run(['npx', 'playwright', 'install', 'chromium'], context: video_context());
}

#[AsTask(description: 'Films the demo scenario in the running stack (castor start first, with a LLM key)')]
function capture(): void
{
    io()->title('Filming the demo scenario');

    check_prerequisites();
    if (!is_dir(variable('root_dir') . '/video/node_modules')) {
        install();
    }

    run(['node', 'capture.mjs'], context: video_context()->withEnvironment([
        'BASE_URL' => 'https://' . variable('root_domain'),
    ])->withTimeout(null));

    io()->success('Capture written in video/public/capture/. Run "castor video:render" next.');
}

#[AsTask(description: 'Renders the video from the last capture, in video/out/hop-garden.mp4')]
function render(): void
{
    io()->title('Rendering the video');

    check_prerequisites();
    if (!is_file(variable('root_dir') . '/video/public/capture/markers.json')) {
        capture();
    }

    run(['npx', 'remotion', 'render', 'HopGarden', 'out/hop-garden.mp4'], context: video_context()->withTimeout(null));

    io()->success('Video rendered: video/out/hop-garden.mp4');
}

#[AsTask(description: 'Opens Remotion Studio to tweak the video')]
function studio(): void
{
    run(['npx', 'remotion', 'studio'], context: video_context()->withTimeout(null)->toInteractive());
}

function check_prerequisites(): void
{
    foreach (['node', 'npx', 'ffmpeg'] as $executable) {
        check(
            \sprintf('Checking %s is available', $executable),
            \sprintf('%s is required on the host for the video (Node.js 20+ and ffmpeg).', $executable),
            static fn () => null !== (new ExecutableFinder())->find($executable),
        );
    }
}

function video_context(): \Castor\Context
{
    return context()->withWorkingDirectory(variable('root_dir') . '/video');
}
