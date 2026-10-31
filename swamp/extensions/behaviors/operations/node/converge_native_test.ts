import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
    behavior,
    summarizeConverge,
    verifyCommands,
} from "./converge_native.ts";
import type { Snapshot } from "./converge_direct_contract.ts";

const intent = (): Snapshot => ({
    schema_version: 1,
    node_id: 12,
    agent_sha256: "a".repeat(64),
    force_artifacts: false,
    first_visit: false,
    artifacts: [{
        name: "tmpfiles",
        digest: "b".repeat(64),
        required: true,
        repair: false,
    }],
    payloads: { tmpfiles: { rule: "e /tmp/orbit-test - - - 1d" } },
    network: null,
    refusal: null,
});
const args = () => ({
    operation_id: 777,
    node_id: 12,
    kind: "node.converge" as const,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
    intent: intent(),
});
const step = (value: unknown, exitCode = 0) => ({
    status: "succeeded",
    outputs: { exitCode, stdout: JSON.stringify(value) },
});

Deno.test("native command gates refuse missing or unsuccessful evidence", () => {
    assertEquals(
        verifyCommands({ required: false, names: ["tmpfiles"], steps: {} }),
        { ready: true },
    );
    assertEquals(
        verifyCommands({
            required: true,
            names: ["tmpfiles"],
            steps: { tmpfiles: step({ ok: true }) },
        }),
        { ready: true },
    );
    for (
        const steps of [{}, { tmpfiles: step({ ok: false }) }, {
            tmpfiles: step({ ok: true }, 1),
        }]
    ) {
        assertThrows(() =>
            verifyCommands({ required: true, names: ["tmpfiles"], steps })
        );
    }
});

Deno.test("native preparation refuses unproved logical proxy shell placement before writing", async () => {
    for (const node_id of [1, 18]) {
        await assertRejects(
            () =>
                behavior.methods.prepare.execute({
                    ...args(),
                    node_id,
                    intent: { ...intent(), node_id },
                }, {
                    writeResource: () => {
                        throw new Error(
                            "Preparation must refuse before a write",
                        );
                    },
                }),
            Error,
            "shared native proxy transport",
        );
    }
});

Deno.test("native input refuses a foreign Node without writing public data", async () => {
    let writes = 0;
    await assertRejects(() =>
        behavior.methods.prepare.execute({ ...args(), node_id: 13 }, {
            writeResource: () => {
                writes++;
                return Promise.resolve({});
            },
        })
    );
    assertEquals(writes, 0);
});

Deno.test("native public input chunks round-trip large Unicode payloads without shell argument limits", async () => {
    const input = args();
    input.intent.payloads.large = "λ𓀀".repeat(220000);
    let recorded: unknown;
    const metadata = await behavior.methods.prepare.execute(input, {
        writeResource: (_spec, _name, value) => {
            recorded = value;
            return Promise.resolve({});
        },
    });
    assertEquals(metadata.allowed, true);
    assertEquals(recorded, metadata);
    assertEquals(
        metadata.chunks.every((c, i) =>
            c.index === i && c.data.length <= 49152
        ),
        true,
    );
    const bytes = metadata.chunks.flatMap((c) =>
        Array.from(atob(c.data), (v) => v.charCodeAt(0))
    );
    assertEquals(
        JSON.parse(new TextDecoder().decode(new Uint8Array(bytes))),
        input.intent,
    );
    assertEquals(
        metadata.intent_file,
        `/tmp/orbit-node-converge-${input.run_id}/intent.json`,
    );
});

Deno.test("native result records only the admitted successful artifact", () => {
    assertEquals(
        summarizeConverge({
            ...args(),
            steps: { tmpfiles: step({ ok: true, changed: true }) },
        }),
        {
            footprint_proven: true,
            artifacts: [{ name: "tmpfiles", status: "applied" }],
        },
    );
});

Deno.test("native result rejects empty, missing, nonzero and failed command evidence", () => {
    for (
        const steps of [{}, { tmpfiles: step({}) }, {
            tmpfiles: step({ ok: true }, 1),
        }, { tmpfiles: { status: "failed" } }]
    ) {
        assertEquals(summarizeConverge({ ...args(), steps }), {
            footprint_proven: false,
            artifacts: [],
            error_code: "node.footprint_failed",
            failed_artifact: "tmpfiles",
        });
    }
});

Deno.test("native result preserves an owning closed refusal without diagnostics", () => {
    assertEquals(
        summarizeConverge({
            ...args(),
            steps: {
                tmpfiles: step({
                    ok: false,
                    error_code: "node.tmpfiles_failed",
                }),
            },
        }),
        {
            footprint_proven: false,
            artifacts: [],
            error_code: "node.tmpfiles_failed",
            failed_artifact: "tmpfiles",
        },
    );
    assertThrows(() =>
        summarizeConverge({
            ...args(),
            steps: {
                tmpfiles: step({ ok: false, error_code: "private diagnostic" }),
            },
        })
    );
});

Deno.test("native result records unchanged artifacts without dispatching a command", () => {
    const input = args();
    input.intent.artifacts[0].required = false;
    assertEquals(summarizeConverge({ ...input, steps: {} }), {
        footprint_proven: true,
        artifacts: [{ name: "tmpfiles", status: "unchanged" }],
    });
});

Deno.test("refused empty intent cannot become proven", () => {
    const input = args();
    input.intent.artifacts = [];
    input.intent.refusal = "node.footprint_failed";
    assertEquals(summarizeConverge({ ...input, steps: {} }), {
        footprint_proven: false,
        artifacts: [],
        error_code: "node.footprint_failed",
    });
});

Deno.test("captured Caddy render refusal retains the canonical skip policy", () => {
    const input = args();
    input.intent.artifacts[0].name = "caddy";
    input.intent.payloads = {
        caddy: { error_code: "caddy.render_failed", stage: "render" },
    };
    assertEquals(
        summarizeConverge({
            ...input,
            steps: {
                caddy: step({
                    ok: false,
                    error_code: "caddy.render_failed",
                    stage: "render",
                }),
            },
        }),
        {
            footprint_proven: true,
            artifacts: [{
                name: "caddy",
                status: "skipped",
                reason: "caddy_render_refused",
            }],
        },
    );
});
