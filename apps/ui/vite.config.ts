import { defineLaunchConfig } from "@nckrtl/launch-ui/vite";
import { defineConfig } from "vite-plus";

const launchConfig = await defineLaunchConfig({
    // Keep the SSR port stable for the Orbit UI runtime.
    inertia: { ssr: { port: 13719 } },
    agentation: true,
});

export default defineConfig(async (environment) => ({
    ...(await launchConfig(environment)),
    fmt: { ignorePatterns: [".agents/**"] },
    // Pre-commit tasks, run against staged files only by `vp staged` from
    // .vite-hooks/pre-commit. Anything they fix is re-staged automatically.
    staged: {
        "*": "vp check --fix",
        "*.php": "vendor/bin/pint",
    },
}));
