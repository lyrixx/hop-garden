import { Config } from '@remotion/cli/config';

Config.setVideoImageFormat('jpeg');
Config.setEntryPoint('src/index.ts');
if (process.env.REMOTION_BROWSER) {
    Config.setBrowserExecutable(process.env.REMOTION_BROWSER);
}
