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
    // VitePlus checks remain app-local. Orbit's root repository owns Git hooks.
    staged: {
        "*": "vp check --fix",
        "*.php": "vendor/bin/pint",
    },
}));
