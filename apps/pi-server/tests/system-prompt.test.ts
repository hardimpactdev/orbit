import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { describe, expect, it } from "vite-plus/test";
import type { BuildSystemPromptOptions } from "@earendil-works/pi-coding-agent";
import { buildSystemPrompt } from "../src/system-prompt.ts";
import serverPackage from "../package.json" with { type: "json" };

// Test-only access to the unexported upstream implementation. Production never deep-imports it.
const upstreamPath = pathToFileURL(
    resolve("node_modules/@earendil-works/pi-coding-agent/dist/core/system-prompt.js"),
).href;
const upstream = await import(/* @vite-ignore */ upstreamPath);
const fixed: BuildSystemPromptOptions = {
    cwd: "/workspace/orbit",
    selectedTools: ["read", "bash", "edit", "write", "search_docs"],
    toolSnippets: {
        read: "Read file contents",
        bash: "Execute bash commands",
        edit: "Edit files",
        write: "Write files",
        search_docs: "Search docs",
    },
    toolGuidelines: {
        read: ["Use read to examine files."],
        search_docs: ["Search before writing framework code."],
    },
    appendSystemPrompt: "You are the Orbit implementer.",
    contextFiles: [{ path: "/workspace/orbit/AGENTS.md", content: "Run scoped checks." }],
    skills: [
        {
            name: "probe",
            description: "A fixed skill.",
            filePath: "/workspace/orbit/.agents/skills/probe/SKILL.md",
            baseDir: "/workspace/orbit/.agents/skills/probe",
            sourceInfo: {
                path: "/workspace/orbit/.agents/skills/probe/SKILL.md",
                source: "project",
                scope: "project",
                origin: "top-level",
            },
            disableModelInvocation: false,
        },
    ],
};

describe("Pi 1.4.0 prompt parity", () => {
    it.each([
        fixed,
        { ...fixed, customPrompt: "Custom system instructions." },
        { ...fixed, hiddenTools: ["read"], sections: { orbit: "Extra section." } },
        { ...fixed, forceSystemPrompt: "Opaque prompt." },
    ])("matches the installed upstream builder for fixed inputs", (input) => {
        expect(serverPackage.dependencies["@earendil-works/pi-coding-agent"]).toBe("1.4.0");
        expect(buildSystemPrompt(input)).toBe(upstream.buildSystemPrompt(input));
    });
});
