import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { directHttp } from "../../../../tests/_support/native_direct.ts";
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fleetRelease, observed, windowOpen } from "./release.ts";

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

Deno.test("each failed observer step names its own refusal and stops the fleet", () => {
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

Deno.test("every step runs on 209 and only a rollout publishes the observer", async () => {
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
    for (const name of ["gate", "cli", "record"]) {
        assertEquals(steps[name].labels, { role: "swamp" }, name);
    }
    for (
        const name of [
            "observer_fetch",
            "observer_read",
            "observer_restart",
            "observer_check",
            "observer",
        ]
    ) {
        assertEquals(steps[name].labels, { role: "swamp" }, name);
        assert(
            steps[name].guard.startsWith(
                '${{ inputs.kind != "fleet.rollout" ||',
            ),
            name,
        );
    }
    assertEquals(
        steps.observer_fetch.task.behaviorType,
        "@swamp/cfgmgmt/fetch",
    );
});

Deno.test("closed window defers shared nodes; daytime nodes bypass the window", () => {
    for (const at of ["2026-10-31T12:30:00Z", "2026-07-01T12:30:00Z"]) {
        assertEquals(windowOpen(false, new Date(at)), false);
        assertEquals(windowOpen(true, new Date(at)), true);
    }
    for (
        const at of [
            "2026-10-31T02:00:00Z",
            "2026-10-31T04:59:00Z",
            "2026-07-01T01:00:00Z",
            "2026-07-01T03:59:00Z",
        ]
    ) {
        assertEquals(windowOpen(false, new Date(at)), true);
    }
    for (
        const at of [
            "2026-10-31T01:59:00Z",
            "2026-10-31T05:00:00Z",
            "2026-07-01T00:59:00Z",
            "2026-07-01T04:00:00Z",
        ]
    ) {
        assertEquals(windowOpen(false, new Date(at)), false);
    }
});

Deno.test("closed window prevents installation and the run still succeeds", async () => {
    const directory = await Deno.makeTempDir();
    const file = `${directory}/state.json`;
    const node = {
        node_id: 2,
        user: "node",
        release: null,
        profile: "{}",
        skip_reason: null,
        plan_error: null,
        daytime: false,
        agent: null,
        ssh: null,
    };
    await Deno.writeTextFile(
        file,
        JSON.stringify({
            bound: true,
            calls: [],
            operation: {
                id: 7,
                kind: "fleet.rollout",
                node_id: 1,
                status: "running",
                cancel_requested: false,
                input: { nodes: [node] },
            },
        }),
    );
    try {
        const release = fleetRelease(
            () => directHttp(file),
            () => new Date("2026-10-31T12:30:00Z"),
        );
        const test = createModelTestContext({
            globalArgs: {
                gatewayUrl: "https://gateway.invalid",
                caFile: "/unused",
            },
        });
        const context = Object.assign(test.context, {
            extensionFile: (path: string) =>
                new URL(path, import.meta.url).pathname,
        }) as unknown as Parameters<typeof release.methods.prepare.execute>[1];
        const args = {
            operation_id: 7,
            kind: "fleet.rollout" as const,
            node_id: 1,
            run_id: crypto.randomUUID(),
        };
        const admission = await release.methods.prepare.execute(args, context);
        const gate = await release.methods.gate.execute({
            ...args,
            target_node_id: 2,
            previous: {},
        }, context);
        assertEquals(gate.install, false);
        assertEquals([gate.result.outcome, gate.result.error_code], [
            "deferred",
            "fleet.window_closed",
        ]);
        await release.methods.finish.execute({
            ...args,
            admission,
            node_results: { "node-0": { outputs: { record: gate.result } } },
        }, context);
        const { operation } = JSON.parse(await Deno.readTextFile(file));
        assertEquals(operation.status, "succeeded");
        assertEquals(operation.result.nodes, [gate.result]);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

Deno.test("a failed or missing earlier Node keeps later fleet participants pending", async () => {
    const directory = await Deno.makeTempDir();
    const file = `${directory}/state.json`;
    const node = {
        node_id: 2,
        user: "node",
        release: null,
        profile: "{}",
        skip_reason: null,
        plan_error: null,
        daytime: true,
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
    try {
        await Deno.writeTextFile(
            file,
            JSON.stringify({
                bound: true,
                calls: [],
                operation: {
                    id: 7,
                    kind: "fleet.rollout",
                    node_id: 1,
                    status: "running",
                    cancel_requested: false,
                    input: {
                        nodes: [node, { ...node, node_id: 3 }, {
                            ...node,
                            node_id: 4,
                        }],
                    },
                },
            }),
        );
        const release = fleetRelease(() => directHttp(file));
        const test = createModelTestContext({
            globalArgs: {
                gatewayUrl: "https://gateway.invalid",
                caFile: "/unused",
            },
        });
        const context = Object.assign(test.context, {
            extensionFile: (path: string) =>
                new URL(path, import.meta.url).pathname,
        }) as unknown as Parameters<typeof release.methods.gate.execute>[1];
        const args = {
            operation_id: 7,
            kind: "fleet.rollout" as const,
            node_id: 1,
            run_id: crypto.randomUUID(),
            target_node_id: 4,
        };
        const first = {
            ...record,
            node_id: 2,
            step: "verify",
            outcome: "converged",
        };
        const second = {
            ...record,
            node_id: 3,
            step: "cli",
            outcome: "failed",
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
            step: "verify",
            outcome: "converged",
            error_code: null,
        };
        const gate = await release.methods.gate.execute({
            ...args,
            previous: {
                "node-0": { outputs: { record: first, observer: first } },
                "node-1": {
                    outputs: { record: completed, observer: completed },
                },
            },
        }, context);
        assertEquals([gate.install, gate.result.outcome], [true, "unchanged"]);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

Deno.test("a rollout admitted before the record tables were dropped still prepares", async () => {
    const directory = await Deno.makeTempDir();
    const file = `${directory}/state.json`;
    const node = {
        node_id: 2,
        row_id: 41,
        user: "node",
        release: null,
        profile: "{}",
        skip_reason: null,
        plan_error: null,
        daytime: false,
        agent: null,
        ssh: null,
    };
    await Deno.writeTextFile(
        file,
        JSON.stringify({
            bound: true,
            calls: [],
            operation: {
                id: 7,
                kind: "fleet.rollout",
                node_id: 1,
                status: "running",
                cancel_requested: false,
                input: {
                    rollout_id: 668,
                    remaining: 0,
                    commit: "a".repeat(40),
                    nodes: [node],
                },
            },
        }),
    );
    try {
        const release = fleetRelease(
            () => directHttp(file),
            () => new Date("2026-10-31T12:30:00Z"),
        );
        const test = createModelTestContext({
            globalArgs: {
                gatewayUrl: "https://gateway.invalid",
                caFile: "/unused",
            },
        });
        const context = Object.assign(test.context, {
            extensionFile: (path: string) =>
                new URL(path, import.meta.url).pathname,
        }) as unknown as Parameters<typeof release.methods.prepare.execute>[1];
        const admission = await release.methods.prepare.execute({
            operation_id: 7,
            kind: "fleet.rollout" as const,
            node_id: 1,
            run_id: crypto.randomUUID(),
        }, context);
        assertEquals([admission.ready, admission.nodes.length], [true, 1]);
    } finally {
        await Deno.remove(directory, { recursive: true });
    }
});

for (const kind of ["fleet.rollout", "fleet.cli_converge"] as const) {
    Deno.test(`${kind} omits CLI publication for a Node with no caller`, async () => {
        const directory = await Deno.makeTempDir();
        const file = `${directory}/state.json`;
        const node = {
            node_id: 2, user: "orbit", release: null, profile: "", skip_reason: "cli_not_required", plan_error: null,
            daytime: true, agent: { ...agent, url: "https://artifacts.invalid/agent" },
            ssh: { control_node_id: 209, identity_file: "/identity", target: { host: "node.invalid", user: "orbit", port: 22, host_keys: ["public-key"] } },
        };
        try {
            await Deno.writeTextFile(file, JSON.stringify({
                bound: true, calls: [], operation: {
                    id: 7, kind, node_id: 1, status: "running", cancel_requested: false,
                    input: { nodes: [node, { ...node, node_id: 3 }] },
                },
            }));
            const release = fleetRelease(() => directHttp(file));
            const test = createModelTestContext({ globalArgs: { gatewayUrl: "https://gateway.invalid", caFile: "/unused" } });
            const context = Object.assign(test.context, { extensionFile: (path: string) => new URL(path, import.meta.url).pathname }) as unknown as Parameters<typeof release.methods.gate.execute>[1];
            const args = { operation_id: 7, kind, node_id: 1, run_id: crypto.randomUUID() };
            const gate = await release.methods.gate.execute({ ...args, target_node_id: 2, previous: {} }, context);
            assertEquals(gate.install, false);
            const record = await release.methods.record.execute({
                ...args, target_node_id: 2, gate, native_cli: { status: "skipped", result: null },
            }, context);
            assertEquals(record.cli_version, null);
            assertEquals(record.ok, true);
            if (kind === "fleet.rollout") {
                assertEquals([record.outcome, record.step], ["unchanged", "agent"]);
                // The next Node must wait for the observer, not treat CLI omission as a completed visit.
                const pending = await release.methods.gate.execute({ ...args, target_node_id: 3, previous: { "node-0": { outputs: { record } } } }, context);
                assertEquals(pending.result.outcome, "pending");
                const observer = await release.methods.observer.execute({
                    ...args, target_node_id: 2, record, agent: node.agent, fetch: "succeeded", changed: true, restart: "succeeded", running,
                }, context);
                assertEquals([observer.outcome, observer.step, observer.agent_version], ["converged", "verify", agent.version]);
                const next = await release.methods.gate.execute({ ...args, target_node_id: 3, previous: { "node-0": { outputs: { record, observer } } } }, context);
                assertEquals([next.result.outcome, next.result.step], ["unchanged", "agent"]);
            } else {
                assertEquals([record.outcome, record.step], ["skipped", "admission"]);
            }
        } finally {
            await Deno.remove(directory, { recursive: true });
        }
    });
}
