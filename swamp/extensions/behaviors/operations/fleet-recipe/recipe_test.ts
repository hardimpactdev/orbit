import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { parse } from "jsr:@std/yaml@1.0.12";
import { behavior } from "./assets.ts";

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
                    hosts[0].transport.hostKeys.includes(
                        "inputs.intent.ssh.target.host_keys",
                    ),
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
