import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { DockerMigrationInput, terminal } from "./assets.ts";
const pins = {
    recipe: "fixture",
    snapshot: "20261031T000000Z",
    architecture: "amd64",
    docker_version: "29.1.3",
    containerd_version: "2.2.2",
    process_ids: ["42"],
    process_states: { "42": "running" },
    process_specs: {
        "42": {
            name: "orbit-process-42-app",
            hash: "a".repeat(64),
            volumes: [],
        },
    },
    fallback: false,
};
Deno.test("Docker public pins require complete exact positive Process membership", () => {
    DockerMigrationInput.parse(pins);
    for (
        const change of [
            { architecture: "arm64" },
            { process_ids: ["0"] },
            { process_states: {} },
            { process_specs: {} },
            {
                process_specs: {
                    ...pins.process_specs,
                    "43": pins.process_specs["42"],
                },
            },
        ]
    ) assertThrows(() => DockerMigrationInput.parse({ ...pins, ...change }));
});
Deno.test("Docker terminal retains failure even if a final observation looks healthy", () => {
    const output = terminal({
        "process-0": { status: "failed" },
        report: {
            status: "succeeded",
            outputs: {
                stdout: '{"healthy":true,"containers":[],"process_ids":[]}',
            },
        },
    }, "migrate");
    assertEquals(output.healthy, false);
    assertEquals(JSON.parse(output.stdout).stage, "process-0");
});
Deno.test("Docker approved fallback tolerates only the first engine-start refusal", () => {
    const steps = {
        "engine-start": { status: "failed" },
        engine: { status: "succeeded", outputs: { fallback: true } },
        report: {
            status: "succeeded",
            outputs: {
                stdout:
                    '{"healthy":true,"fallback":true,"containers":[],"process_ids":[]}',
            },
        },
    };
    assertEquals(terminal(steps, "migrate").healthy, true);
    assertEquals(
        terminal(
            { ...steps, "fallback-start": { status: "failed" } },
            "migrate",
        ).healthy,
        false,
    );
});
Deno.test("Docker terminal plan publishes only bounded public report fields", () => {
    const output = terminal({
        inventory: {
            status: "succeeded",
            outputs: {
                stdout:
                    '{"healthy":true,"process_ids":[],"plan":{"recipe":"fixture"},"unmanaged":["public-id"],"unexpected":"discard"}',
            },
        },
    }, "plan");
    assertEquals(output.healthy, true);
    assertEquals(JSON.parse(output.stdout), {
        healthy: true,
        fallback: false,
        containers: [],
        process_ids: [],
        plan: { recipe: "fixture" },
        planned: true,
    });
});
