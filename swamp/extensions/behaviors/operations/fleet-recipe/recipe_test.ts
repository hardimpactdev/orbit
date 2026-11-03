import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { parse } from "jsr:@std/yaml@1.0.12";
import { assembleFootprint, behavior, publicResult } from "./assets.ts";

Deno.test("all node targets use 209 with their saved host keys", async () => {
    const text = await Deno.readTextFile(
        new URL(
            "../../../../workflows/workflow-operation-fleet-recipe-direct.yaml",
            import.meta.url,
        ),
    );
    const source = parse(text) as {
        jobs: {
            steps: {
                labels: unknown;
                task?: {
                    globalArgs?: {
                        hosts?: { transport: { hostKeys: string } }[];
                    };
                };
            }[];
        }[];
    };
    for (const job of source.jobs) {
        for (const step of job.steps) {
            assertEquals(step.labels, { role: "swamp" });
            const hosts = step.task?.globalArgs?.hosts;
            if (hosts?.length) {
                assert(
                    ["inputs.intent.ssh.target.host_keys", "inputs.intent.footprint.network.apply.hub.ssh.target.host_keys"]
                        .some((pin) => hosts[0].transport.hostKeys.includes(pin)),
                );
            }
        }
    }
    assert(!text.includes("/api/v1/direct-operations"));
    assert(!text.includes('orbit-node-" +'));
});

const intent = {
    node_id: 9,
    identity: "recipe-identity",
    snapshot: "snapshot",
    agent_sha256: "a".repeat(64),
    cli_version: "0.12.0",
    automatic: false,
    daytime: false,
    recipe: { user: "orbit", snapshot: "snapshot" },
    php: null,
    incus_hold: [] as [],
    footprint: {
        node_id: 9,
        agent_sha256: "a".repeat(64),
        refusal: null as string | null,
        artifacts: [],
        payloads: {},
        network: null,
    },
    cli: {
        user: "orbit",
        release: { version: "0.12.0" },
        profile: "public-profile",
        skip_reason: null,
        plan_error: null,
    },
    ssh: {
        control_node_id: 209 as const,
        identity_file: "/approved",
        target: { host: "192.0.2.9", user: "orbit", port: 22, host_keys: ["public-host-key"] },
    },
};
Deno.test("refusal before first host effect writes no ready resource", async () => {
    const { context, getWrittenResources } = createModelTestContext();
    await assertRejects(
        () =>
            behavior.methods.validate.execute({
                intent: {
                    ...intent,
                    footprint: { ...intent.footprint, refusal: "node.footprint_failed" },
                },
                node_id: 9,
            }, { ...context, extensionFile: (path) => new URL(path, import.meta.url).pathname }),
        Error,
        "node.footprint_failed",
    );
    assertEquals(getWrittenResources(), []);
});

Deno.test("Caddy render refusal retains a skipped artifact without failing the footprint", () => {
    assertEquals(assembleFootprint({
        ...intent.footprint,
        artifacts: [{ name: "caddy", required: true }],
        payloads: { caddy: { error_code: "caddy.render_failed", stage: "render" } },
    }, {}), {
        artifacts: [{ name: "caddy", status: "skipped", reason: "caddy_render_refused" }],
        footprint_proven: true,
    });
});

Deno.test("runtime footprint requires every admitted PHP version and tolerates residue refusal", () => {
    const footprint = {
        ...intent.footprint,
        roles: { php: { "8.4": {}, "8.5": {} } },
        artifacts: ["php-runtime", "private-dns", "route-residue"].map((name) => ({ name, required: true })),
    };
    const steps = {
        "Configure PHP-FPM-8.4": { status: "succeeded" },
        "Configure PHP-FPM-8.5": { status: "succeeded" },
        "Install private DNS": { status: "succeeded" },
        "Clear Route residue": { status: "failed" },
    };
    assertEquals(assembleFootprint(footprint, steps), {
        artifacts: [
            { name: "php-runtime", status: "applied" },
            { name: "private-dns", status: "applied" },
            { name: "route-residue", status: "skipped", reason: "route_residue_cleanup_failed" },
        ],
        footprint_proven: true,
    });
    assertEquals(assembleFootprint(footprint, {
        ...steps, "Configure PHP-FPM-8.5": { status: "failed" },
    }), {
        artifacts: [
            { name: "private-dns", status: "applied" },
            { name: "route-residue", status: "skipped", reason: "route_residue_cleanup_failed" },
        ],
        footprint_proven: false,
        failed_artifact: "php-runtime",
        error_code: "node.footprint_failed",
    });
});

Deno.test("recipe result keeps delivery identity and tolerates a failed residue child", () => {
    const result = publicResult({
        intent: {
            ...intent,
            footprint: {
                ...intent.footprint,
                artifacts: [{ name: "route-residue", required: true }],
            },
        },
        steps: {
            "Clear Route residue": { status: "failed" },
            delivery: { status: "succeeded", outputs: { stdout: JSON.stringify({ identity: intent.identity }) } },
        },
    });
    const expected = {
        identity: intent.identity,
        footprint: {
            artifacts: [{ name: "route-residue", status: "skipped", reason: "route_residue_cleanup_failed" }],
            footprint_proven: true,
        },
    };
    assertEquals(result, { ...expected, stdout: JSON.stringify(expected) });
});
