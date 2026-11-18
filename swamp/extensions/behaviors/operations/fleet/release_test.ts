import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fleetRelease, observed } from "./release.ts";

const record = {
    node_id: 12,
    outcome: "unchanged" as const,
    error_code: null,
    cli_version: "0.100.0",
    agent_version: null,
    step: "cli" as const,
    ok: true,
};
const agent = { sha256: "b".repeat(64), version: "0.5.0" };
const running = `${"b".repeat(64)}  /proc/41/exe\nactive\n`;
const run = (over: Record<string, unknown>) =>
    observed(record, {
        agent,
        fetch: "succeeded",
        changed: false,
        restart: "skipped",
        running,
        ...over,
    });
const node = {
    node_id: 2,
    user: "node",
    release: null,
    profile: "{}",
    skip_reason: null,
    plan_error: null,
    agent: null,
    ssh: {
        control_node_id: 209,
        identity_file: "/identity",
        target: {
            host: "node.invalid",
            user: "orbit",
            port: 22,
            host_keys: ["public-key"],
        },
    },
};
const args = {
    operation_id: 7,
    kind: "fleet.rollout" as const,
    node_id: 1,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
    const release = fleetRelease();
    const test = createModelTestContext();
    const context = test.context as unknown as Parameters<
        typeof release.methods.prepare.execute
    >[1];
    return { release, context };
}

Deno.test("a pinned, running observer is unchanged; a fetch or restart makes it converged", () => {
    assertEquals(run({}), {
        ...record,
        agent_version: "0.5.0",
        step: "verify",
    });
    assertEquals(
        run({ changed: true, restart: "succeeded" }).outcome,
        "converged",
    );
    assertEquals(run({ restart: "succeeded" }).outcome, "converged");
});

Deno.test("each failed observer step names its refusal and stops the fleet", () => {
    assertEquals(run({ fetch: "failed" }), {
        ...record,
        step: "agent",
        error_code: "agent.download_failed",
        outcome: "failed",
        ok: false,
    });
    assertEquals(run({ restart: "failed" }).error_code, "agent.restart_failed");
    assertEquals(run({ running: null }).error_code, "agent.observer_unhealthy");
    assertEquals(
        run({ running: `${"c".repeat(64)}  /proc/41/exe\nactive\n` })
            .error_code,
        "agent.observer_unhealthy",
    );
    assertEquals(
        run({ running: `${"b".repeat(64)}  /proc/41/exe\nfailed\n` }).ok,
        false,
    );
});

Deno.test("every child step runs on 209 and only a rollout publishes the observer", async () => {
    const source = parse(
        await Deno.readTextFile(
            new URL(
                "../../../../workflows/workflow-fleet-release-node.yaml",
                import.meta.url,
            ),
        ),
    ) as {
        jobs: {
            steps: {
                name: string;
                labels: unknown;
                guard: string;
                task: { behaviorType: string };
            }[];
        }[];
    };
    const steps = Object.fromEntries(
        source.jobs.flatMap((j) => j.steps).map((s) => [s.name, s]),
    );
    for (
        const name of [
            "gate",
            "cli",
            "record",
            "observer_fetch",
            "observer_read",
            "observer_restart",
            "observer_check",
            "observer",
        ]
    ) {
        assertEquals(steps[name].labels, { role: "swamp" }, name);
        if (name.startsWith("observer")) {
            assert(
                steps[name].guard.startsWith(
                    '${{ inputs.kind != "fleet.rollout" ||',
                ),
                name,
            );
        }
    }
    assertEquals(
        steps.observer_fetch.task.behaviorType,
        "@swamp/cfgmgmt/fetch",
    );
});

Deno.test("full admitted input prepares and every Node publishes at once, at any hour", async () => {
    const { release, context } = fixture();
    const intent = { nodes: [node] };
    const admission = await release.methods.prepare.execute(
        { ...args, intent },
        context,
    );
    assertEquals(admission, intent);
    const gate = await release.methods.gate.execute({
        ...args,
        intent,
        target_node_id: 2,
        previous: {},
    }, context);
    assertEquals(gate.install, true);
    assertEquals(
        [gate.result.outcome, gate.result.error_code, gate.result.ok],
        ["unchanged", null, true],
    );
});

Deno.test("retired rollout fields are refused rather than silently accepted", async () => {
    const { release, context } = fixture();
    for (
        const field of [{ remaining: 0 }, { rollout_id: 668 }, {
            commit: "a".repeat(40),
        }]
    ) {
        await assertRejects(
            () =>
                release.methods.prepare.execute({
                    ...args,
                    intent: { nodes: [node], ...field },
                }, context),
            Error,
            "Unrecognized key",
        );
    }
    // The release fleet has no time window, so the per-Node window policy is gone too.
    await assertRejects(
        () =>
            release.methods.prepare.execute({
                ...args,
                intent: { nodes: [{ ...node, ...{ daytime: false } }] },
            }, context),
        Error,
        "Unrecognized key",
    );
});

Deno.test("a failed or missing earlier Node keeps later fleet participants pending", async () => {
    const { release, context } = fixture();
    const intent = {
        nodes: [node, { ...node, node_id: 3 }, { ...node, node_id: 4 }],
    };
    const first = {
        ...record,
        node_id: 2,
        step: "verify" as const,
        outcome: "converged" as const,
    };
    const second = {
        ...record,
        node_id: 3,
        outcome: "failed" as const,
        error_code: "cli.install_failed",
        ok: false,
    };
    for (
        const previous of [{}, {
            "node-0": { outputs: { record: first, observer: first } },
            "node-1": { outputs: { record: second } },
        }]
    ) {
        const gate = await release.methods.gate.execute({
            ...args,
            intent,
            target_node_id: 4,
            previous,
        }, context);
        assertEquals([gate.install, gate.result.outcome, gate.result.ok], [
            false,
            "pending",
            false,
        ]);
    }
    const completed = {
        ...second,
        ok: true,
        step: "verify" as const,
        outcome: "converged" as const,
        error_code: null,
    };
    const gate = await release.methods.gate.execute({
        ...args,
        intent,
        target_node_id: 4,
        previous: {
            "node-0": { outputs: { record: first, observer: first } },
            "node-1": { outputs: { record: completed, observer: completed } },
        },
    }, context);
    assertEquals([gate.install, gate.result.outcome], [true, "unchanged"]);
    const result = await release.methods.finish.execute({
        ...args,
        admission: intent,
        node_results: {
            "node-0": { outputs: { record: first, observer: first } },
            "node-1": { outputs: { record: second } },
            "node-2": {
                outputs: {
                    record: { ...gate.result, outcome: "pending", ok: false },
                },
            },
        },
    }, context);
    assertEquals(result.error_code, "cli.install_failed");
    assertEquals(result.nodes.map((n) => n.outcome), [
        "converged",
        "failed",
        "pending",
    ]);
});

Deno.test("missing child output is uncertain and foreign Node output is refused", async () => {
    const { release, context } = fixture();
    const admission = { nodes: [node] };
    assertEquals(
        (await release.methods.finish.execute({
            ...args,
            admission,
            node_results: {},
        }, context)).error_code,
        "fleet.release_uncertain",
    );
    await assertRejects(
        () =>
            release.methods.finish.execute({
                ...args,
                admission,
                node_results: {
                    "node-0": {
                        outputs: { record: { ...record, step: "verify" } },
                    },
                },
            }, context),
        Error,
        "Native Node output differs from admitted order",
    );
    assertEquals(
        await release.methods.finish.execute({
            ...args,
            admission: { nodes: [] },
            node_results: {},
        }, context),
        { nodes: [], error_code: null },
    );
});

for (const kind of ["fleet.rollout", "fleet.cli_converge"] as const) {
    Deno.test(`${kind} omits CLI publication for a Node with no caller`, async () => {
        const { release, context } = fixture();
        const noCli = {
            ...node,
            skip_reason: "cli_not_required",
            agent: { ...agent, url: "https://artifacts.invalid/agent" },
        };
        const intent = { nodes: [noCli, { ...noCli, node_id: 3 }] };
        const base = { ...args, kind, intent };
        const gate = await release.methods.gate.execute({
            ...base,
            target_node_id: 2,
            previous: {},
        }, context);
        assertEquals(gate.install, false);
        const result = await release.methods.record.execute({
            ...args,
            kind,
            target_node_id: 2,
            gate,
            native_cli: { status: "skipped", result: null },
        }, context);
        assertEquals([result.cli_version, result.ok], [null, true]);
        if (kind === "fleet.rollout") {
            assertEquals([result.outcome, result.step], ["unchanged", "agent"]);
            const pending = await release.methods.gate.execute({
                ...base,
                target_node_id: 3,
                previous: { "node-0": { outputs: { record: result } } },
            }, context);
            assertEquals(pending.result.outcome, "pending");
            const observer = await release.methods.observer.execute({
                ...args,
                kind,
                target_node_id: 2,
                record: result,
                agent: noCli.agent,
                fetch: "succeeded",
                changed: true,
                restart: "succeeded",
                running,
            }, context);
            assertEquals([
                observer.outcome,
                observer.step,
                observer.agent_version,
            ], ["converged", "verify", agent.version]);
            const next = await release.methods.gate.execute({
                ...base,
                target_node_id: 3,
                previous: {
                    "node-0": { outputs: { record: result, observer } },
                },
            }, context);
            assertEquals([next.result.outcome, next.result.step], [
                "unchanged",
                "agent",
            ]);
        } else {
            assertEquals([result.outcome, result.step], [
                "skipped",
                "admission",
            ]);
        }
    });
}
