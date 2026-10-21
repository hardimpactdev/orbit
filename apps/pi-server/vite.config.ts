import { defineConfig } from "vite-plus";

export default defineConfig({
    test: {
        fileParallelism: true,
        maxWorkers: "100%",
        include: ["tests/**/*.test.ts"],
        environment: "node",
        testTimeout: 20_000,
    },
    lint: {
        jsPlugins: [{ name: "vite-plus", specifier: "vite-plus/oxlint-plugin" }],
        rules: { "vite-plus/prefer-vite-plus-imports": "error" },
        options: { typeAware: true, typeCheck: true },
    },
});
