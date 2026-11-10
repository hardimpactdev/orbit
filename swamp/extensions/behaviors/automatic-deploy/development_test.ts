import {
    assertEquals,
    assertExists,
    assertRejects,
} from "jsr:@std/assert@1.0.19";
import { tickDevelopment } from "./development.ts";
import { GatewayClient, type Http } from "./gateway.ts";
import type { Check, Operation, Row } from "./policy.ts";
import { PolicyError } from "./policy.ts";
const A = "a".repeat(40), B = "b".repeat(40), now = "2026-10-31T05:00:00.000Z";
function fixture() {
    const instance = {
        id: 7,
        node_id: 9,
        name: "default",
        status: "active",
        source_layout: "checkout",
        seed_commit: A,
        development_projection_route_id: null as number | null,
        route: null as { id: number } | null,
        auto_deploy: {
            policy_owner: "swamp",
            eligible: true,
            development_eligible: true,
            gate: null as string | null,
            resumed_after: 0,
            history_version: "f".repeat(64) as string | null,
        },
    };
    const listed: unknown[] = [instance];
    const memory = new Map<string, Record<string, unknown>>(),
        requests: {
            method: string;
            path: string;
            body: unknown;
            key?: string;
        }[] = [];
    const history: Row[] = [], starts: string[] = [], checks: Check[] = [];
    const writes: { name: string; version: number }[] = [];
    const operationReads: number[] = [];
    const source = { head: B };
    let holder = false,
        lost = false,
        busy = false,
        receiptReads = 0,
        repairStatus = "queued";
    const operation: Operation = {
        id: 100,
        kind: "instance.deploy",
        status: "queued",
        input: { commit: B },
        triggered_by: "swamp:auto-deploy",
        result: null,
    };
    const http: Http = (method, path, body, key) => {
        requests.push({ method, path, body, key });
        let data: unknown;
        if (path === "/api/v1/nodes") data = [{ id: 9, roles: ["app-dev"] }];
        else if (path === "/api/v1/instances") data = listed;
        else if (path === "/api/v1/instances/7") data = instance;
        else if (path.endsWith("/deployments")) data = history;
        else if (path.endsWith("/head")) data = source;
        else if (path.includes("/deployment-source/checks?")) {
            data = { gate: instance.auto_deploy.gate, runs: checks };
        } else if (path.includes("/operations?")) {
            data = holder && path.endsWith("queued")
                ? [{ ...operation, target_type: "instance", target_id: 7 }]
                : [];
        } else if (path === "/api/v1/routes/3" && method === "PATCH") {
            data = { id: 200, status: repairStatus };
        } else if (path.endsWith("/policy") && method === "PUT") {
            if (
                (body as { route_operation_id?: number }).route_operation_id ===
                    200
            ) instance.development_projection_route_id = null;
            data = {};
        } else throw new Error("Unexpected HTTP request");
        return Promise.resolve({
            status: 200,
            body: { data: structuredClone(data) },
        });
    };
    const emitted: { instance: number; key: string; body: unknown }[] = [];
    const admissions = {
        checks: (id: number, commit: string, name: string) =>
            new GatewayClient(http).checks(id, commit, name),
        deploy: (_id: number, commit: string, key: string) => {
            assertEquals(
                (memory.get("development-7")?.pending as { key: string }).key,
                key,
            );
            assertEquals(commit, source.head);
            if (busy) {
                throw new PolicyError("operation.target_busy", "Busy", 110);
            }
            starts.push(key);
            if (lost) throw new Error("Lost admission reply");
            return Promise.resolve(structuredClone(operation));
        },
        receipt: () => {
            receiptReads++;
            return Promise.resolve(structuredClone(operation));
        },
        resolve: () => {
            throw new Error("An existing receipt must not be fenced");
        },
        operation: (id: number) => {
            operationReads.push(id);
            return Promise.resolve(
                id === 200
                    ? {
                        ...operation,
                        id: 200,
                        kind: "route.update",
                        status: repairStatus,
                    }
                    : structuredClone(operation),
            );
        },
    };
    const ctx = {
        readResource: (name: string) =>
            Promise.resolve(memory.get(name) ?? null),
        writeResource: (
            _spec: string,
            name: string,
            value: Record<string, unknown>,
        ) => {
            memory.set(name, structuredClone(value));
            const handle = { name, version: writes.length + 1 };
            writes.push(handle);
            return Promise.resolve(handle);
        },
    };
    const run = (at = now, filter = [7]) =>
        tickDevelopment(
            http,
            ctx,
            admissions,
            at,
            (instance, key, body) => emitted.push({ instance, key, body }),
            filter,
        );
    return {
        http,
        ctx,
        instance,
        listed,
        memory,
        requests,
        history,
        checks,
        source,
        starts,
        writes,
        operation,
        operationReads,
        run,
        emitted,
        busy: () => {
            busy = true;
        },
        holder: () => {
            holder = true;
        },
        lost: () => {
            lost = true;
        },
        repairDone: () => {
            repairStatus = "succeeded";
        },
        receiptReads: () => receiptReads,
    };
}
function failed(id: number, manual = false): Row {
    return {
        id,
        kind: "deploy",
        status: "failed",
        commit: B,
        triggered_by: manual ? "operator" : "orbit:auto-deploy",
        failed_step: "preparation",
        error_code: "deployment.interrupted",
        started_at: now,
        finished_at: now,
    };
}
for (
    const [label, status, conclusion, expected] of [
        ["red", "completed", "failure", "gate_failed"],
        ["running", "in_progress", null, "gate_pending"],
        ["green", "completed", "success", "started"],
    ] as const
) {
    Deno.test(`development moved head with ${label} Required checks is ${expected}`, async () => {
        const f = fixture();
        f.instance.auto_deploy.gate = "Required checks";
        f.checks.push({
            name: "Required checks",
            head_sha: B,
            app: "github-actions",
            status,
            conclusion,
        });

        await f.run();

        assertEquals(f.starts.length, expected === "started" ? 1 : 0);
        assertEquals(f.memory.get("development-7")?.result, expected);
        assertEquals(
            f.requests.filter((r) => r.path.includes("/checks?")).map((r) =>
                r.path
            ),
            [
                `/api/v1/instances/7/deployment-source/checks?commit=${B}`,
            ],
        );
    });
}
Deno.test("development retries the same moved head's running check and deploys once green", async () => {
    const f = fixture();
    f.instance.auto_deploy.gate = "Required checks";
    f.checks.push({
        name: "Required checks",
        head_sha: B,
        app: "github-actions",
        status: "in_progress",
        conclusion: null,
    });
    await f.run();
    assertEquals(f.starts, []);
    f.checks[0].status = "completed";
    f.checks[0].conclusion = "success";

    await f.run("2026-10-31T05:01:00.000Z");

    assertEquals(f.starts.length, 1);
    assertEquals(f.memory.get("development-7")?.result, "started");
});
Deno.test("development rereads red checks and deploys the same head after a green rerun", async () => {
    const f = fixture();
    f.instance.auto_deploy.gate = "Required checks";
    f.checks.push({
        name: "Required checks",
        head_sha: B,
        app: "github-actions",
        status: "completed",
        conclusion: "failure",
    });
    await f.run();
    assertEquals(f.starts, []);
    f.checks[0].conclusion = "success";
    await f.run("2026-10-31T05:01:00.000Z");
    assertEquals(f.starts.length, 1);
});
Deno.test("development waits for a missing configured check and never uses unrelated green evidence", async () => {
    for (
        const check of [null, {
            name: "other",
            head_sha: B,
            app: "github-actions",
            status: "completed",
            conclusion: "success",
        }, {
            name: "Required checks",
            head_sha: A,
            app: "github-actions",
            status: "completed",
            conclusion: "success",
        }, {
            name: "Required checks",
            head_sha: B,
            app: "other-app",
            status: "completed",
            conclusion: "success",
        }]
    ) {
        const f = fixture();
        f.instance.auto_deploy.gate = "Required checks";
        if (check) f.checks.push(check);
        await f.run();
        assertEquals(f.starts, []);
    }
});
Deno.test("development policy admits only after Gateway ownership switches", async () => {
    const f = fixture();
    f.instance.auto_deploy.policy_owner = "gateway";
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.memory.size, 0);
});
Deno.test("development policy returns only the final checkpoint handle", async () => {
    const f = fixture();
    const handles = await f.run();
    assertEquals(f.writes.length > 1, true);
    const last = f.writes.at(-1);
    assertExists(last);
    assertEquals(handles, [last]);
    assertEquals(f.starts.length, 1);
    // The start raised one Activity event for the fire result; no step posts it.
    assertEquals(
        f.emitted.map((
            e,
        ) => [e.instance, (e.body as { result: string }).result]),
        [[7, "started"]],
    );
});
Deno.test("native development policy saves the exact pinned intent before admission", async () => {
    const f = fixture();
    await f.run();
    assertEquals(f.starts.length, 1);
    assertEquals(
        (f.memory.get("development-7")?.pending as { operation_id: number })
            .operation_id,
        100,
    );
    assertEquals(
        f.requests.some((r) => /child|executor|secret/.test(r.path)),
        false,
    );
});
Deno.test("an existing target hold starts no competing deployment", async () => {
    const f = fixture();
    f.holder();
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.memory.get("development-7")?.result, "busy");
});
Deno.test("target_busy at admission clears only the unadmitted intent", async () => {
    const f = fixture();
    f.busy();
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.memory.get("development-7")?.pending, null);
    assertEquals(f.memory.get("development-7")?.result, "busy");
});
Deno.test("a lost admission response follows its receipt without a second start", async () => {
    const f = fixture();
    f.lost();
    await assertRejects(f.run);
    await f.run();
    assertEquals(f.starts.length, 1);
    assertEquals(f.receiptReads(), 1);
    assertEquals(f.memory.get("development-7")?.result, "deploying");
});
for (const status of ["succeeded", "failed"] as const) {
    for (const historyVisible of [false, true]) {
        Deno.test(`retained ${status} Operation settles its old commit before a newer green head deploys (history ${historyVisible})`, async () => {
            const f = fixture();
            f.instance.auto_deploy.gate = "Required checks";
            f.checks.push({
                name: "Required checks",
                head_sha: B,
                app: "github-actions",
                status: "completed",
                conclusion: "success",
            });
            f.memory.set("development-7", {
                sequence: 4,
                pending: {
                    commit: A,
                    key: "old-checkout-key",
                    operation_id: 100,
                    manual_after: 0,
                },
                result: "deploying",
                commit: A,
            });
            Object.assign(f.operation, {
                status,
                input: { commit: A },
                result: historyVisible ? { deployment_id: 1 } : null,
                attempts: 1,
            });
            if (historyVisible) {
                f.history.push({ ...failed(1), status, commit: A });
            }

            await f.run();

            assertEquals(f.operationReads, [100]);
            assertEquals(f.receiptReads(), 0);
            const publications = f.requests.filter((r) => r.method === "PUT")
                .map((r) =>
                    r.body as { result: string; commit: string; head: string }
                );
            assertEquals(publications[0].commit, A);
            assertEquals(publications[0].head, B);
            assertEquals(
                publications[0].result,
                status === "succeeded" ? "up_to_date" : "failed",
            );
            // The next fresh tick must not trust the retained ended Operation as a holder.
            if (!f.starts.length) await f.run("2026-10-31T05:01:00.000Z");
            assertEquals(f.starts.length, 1);
            assertEquals(f.starts[0].endsWith(B), true);
            assertEquals(f.memory.get("development-7")?.result, "started");
        });
    }
}
for (const result of ["commit_failed", "failed", "up_to_date"]) {
    Deno.test(`retained ${result} for an old checkout commit cannot block a newer green container head`, async () => {
        const f = fixture();
        f.instance.auto_deploy.gate = "Required checks";
        f.checks.push({
            name: "Required checks",
            head_sha: B,
            app: "github-actions",
            status: "completed",
            conclusion: "success",
        });
        f.memory.set("development-7", {
            sequence: 4,
            result,
            commit: A,
            pending: null,
            unrecorded_failures: [{
                key: "old-attempt",
                commit: A,
                deployment_id: null,
                manual_after: 0,
                finished_at: now,
            }],
        });
        for (let id = 1; id <= 3; id++) {
            f.history.push({ ...failed(id), commit: A });
        }
        await f.run();
        assertEquals(f.starts.length, 1);
        assertEquals(f.starts[0].endsWith(B), true);
    });
}
Deno.test("waiting for green does not reassign an old commit's failed attempts to the new head", async () => {
    const f = fixture();
    f.instance.auto_deploy.gate = "Required checks";
    f.checks.push({
        name: "Required checks",
        head_sha: B,
        app: "github-actions",
        status: "in_progress",
        conclusion: null,
    });
    f.memory.set("development-7", {
        result: "failed",
        commit: A,
        unrecorded_failures: [{
            key: "old-attempt",
            commit: A,
            deployment_id: null,
            manual_after: 0,
            finished_at: now,
        }],
    });
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.memory.get("development-7")?.result, "gate_pending");
    f.checks[0].status = "completed";
    f.checks[0].conclusion = "success";
    await f.run("2026-10-31T05:01:00.000Z");
    assertEquals(f.starts.length, 1);
});
Deno.test("an ended failed Operation without history never authorizes an immediate same-head effect", async () => {
    const f = fixture();
    await f.run();
    f.operation.status = "failed";
    await f.run();
    await f.run();
    assertEquals(f.starts.length, 1);
    assertEquals(f.memory.get("development-7")?.pending, null);
    assertEquals(f.memory.get("development-7")?.result, "backing_off");
});
Deno.test("interrupted development deploys settle, back off and re-admit the head", async () => {
    const f = fixture();
    await f.run();
    Object.assign(f.operation, {
        status: "interrupted",
        error_code: "operation.native_failed",
        result: { deployment_id: 1 },
    });
    f.history.push(failed(1));
    await f.run();
    assertEquals(f.memory.get("development-7")?.pending, null);
    assertEquals(f.memory.get("development-7")?.result, "failed");
    await f.run();
    assertEquals(f.memory.get("development-7")?.result, "backing_off");
    assertEquals(f.starts.length, 1);
    await f.run("2026-10-31T05:10:00.000Z");
    assertEquals(f.starts.length, 2);
    assertEquals(f.starts[0] === f.starts[1], false);
});

Deno.test("interrupted development deploy without evidence backs off once then admits the current head", async () => {
    for (const result of [null, {}]) {
        const f = fixture();
        await f.run();
        Object.assign(f.operation, {
            status: "interrupted",
            error_code: "operation.native_failed",
            result,
        });
        // An orphaned running history row is not terminal deployment evidence.
        f.history.push({
            ...failed(1),
            status: "running",
            commit: null,
            finished_at: null,
        });
        await f.run();
        assertEquals(f.memory.get("development-7")?.pending, null);
        assertEquals(f.memory.get("development-7")?.result, "failed");
        assertEquals(f.history[0].status, "running");
        await f.run("2026-10-31T05:09:59.000Z");
        assertEquals(f.memory.get("development-7")?.result, "backing_off");
        assertEquals(f.starts.length, 1);
        // Use a fresh policy invocation with persisted state, not an in-memory timer.
        f.source.head = "c".repeat(40);
        f.operation.status = "queued";
        await f.run("2026-10-31T05:10:00.000Z");
        assertEquals(f.starts.length, 2);
        assertEquals(f.starts[1].endsWith(f.source.head), true);
        assertEquals(f.starts[0] === f.starts[1], false);
        await f.run("2026-10-31T05:11:00.000Z");
        assertEquals(f.starts.length, 2);
        assertEquals(f.memory.get("development-7")?.result, "deploying");
    }
});

Deno.test("a definite start failure without history uses the same retry backoff", async () => {
    for (
        const code of [
            "swamp.start_refused",
            "swamp.start_unavailable",
            "swamp.vault_sync_unavailable",
        ]
    ) {
        for (const lost of [false, true]) {
            const f = fixture();
            if (lost) f.lost();
            if (lost) await assertRejects(f.run);
            else await f.run();
            Object.assign(f.operation, {
                status: "failed",
                error_code: code,
                attempts: 0,
                result: null,
            });
            await f.run();
            assertEquals(f.memory.get("development-7")?.pending, null);
            assertEquals(f.starts.length, 1);
            // End the lost-response fixture without another synthetic transport failure.
            if (!lost) {
                await f.run();
                assertEquals(f.starts.length, 1);
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "backing_off",
                );
                await f.run("2026-10-31T05:10:00.000Z");
                assertEquals(f.starts.length, 2);
                assertEquals(f.starts[0] === f.starts[1], false);
            }
        }
    }
});

Deno.test("ended failures settle while incomplete interrupted or cancelled receipts retain their hold", async () => {
    for (
        const receipt of [
            {
                status: "failed",
                error_code: "swamp.start_uncertain",
                attempts: 0,
                result: null,
            },
            {
                status: "failed",
                error_code: "swamp.start_refused",
                attempts: 1,
                result: null,
            },
            {
                status: "failed",
                error_code: "swamp.start_unavailable",
                attempts: 1,
                result: null,
            },
            {
                status: "failed",
                error_code: "swamp.start_unavailable",
                attempts: 0,
                result: { deployment_id: 999 },
            },
            {
                status: "failed",
                error_code: "swamp.vault_sync_unavailable",
                attempts: 1,
                result: null,
            },
            {
                status: "failed",
                error_code: "swamp.vault_sync_unavailable",
                attempts: 0,
                result: { deployment_id: 999 },
            },
            {
                status: "cancelled",
                error_code: "swamp.start_refused",
                attempts: 0,
                result: null,
            },
            {
                status: "interrupted",
                error_code: "operation.native_failed",
                result: { deployment_id: 999 },
            },
            {
                status: "failed",
                error_code: "swamp.start_refused",
                attempts: 0,
                result: { deployment_id: 999 },
            },
        ]
    ) {
        const f = fixture();
        await f.run();
        Object.assign(f.operation, receipt);
        await f.run();
        await f.run();
        assertEquals(f.starts.length, 1);
        if (receipt.status === "failed") {
            assertEquals(f.memory.get("development-7")?.pending, null);
            assertEquals(f.memory.get("development-7")?.result, "backing_off");
        } else {
            assertEquals(
                (f.memory.get("development-7")?.pending as {
                    operation_id: number;
                }).operation_id,
                100,
            );
        }
    }
});

Deno.test("development retries and backoff are owned by Swamp and reset after manual work", async () => {
    for (const count of [1, 2, 3]) {
        const f = fixture();
        for (let id = 1; id <= count; id++) {
            f.history.push({
                ...failed(id),
                error_code: "agent.version_mismatch",
                failed_step: "source_preparation",
            });
        }
        await f.run();
        assertEquals(f.starts, []);
        assertEquals(
            f.memory.get("development-7")?.result,
            count === 3 ? "commit_failed" : "backing_off",
        );
        f.history.push(failed(count + 1, true));
        await f.run();
        assertEquals(f.starts.length, 1);
    }
});
Deno.test("pending publication uses the normal Route root before dev deployment", async () => {
    const f = fixture();
    f.instance.development_projection_route_id = 3;
    f.instance.route = { id: 3 };
    await f.run();
    assertEquals(f.starts, []);
    const patches = f.requests.filter((r) => r.method === "PATCH");
    assertEquals(patches.length, 1);
    assertEquals(patches[0].body, {});
    f.repairDone();
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.instance.development_projection_route_id, null);
    await f.run();
    assertEquals(f.starts.length, 1);
    assertEquals(f.requests.filter((r) => r.method === "PATCH").length, 1);
});

Deno.test("only a proven zero-effect admission fence clears a pending development intent", async () => {
    for (
        const receipt of [
            { attempts: 0, result: null },
            { attempts: 1, result: { admission_fenced: true } },
            {
                attempts: 0,
                result: { admission_fenced: true, deployment_id: 7 },
            },
            { attempts: 0, result: { admission_fenced: true } },
        ]
    ) {
        const f = fixture();
        await f.run();
        Object.assign(f.operation, receipt, {
            status: "cancelled",
            error_code: "operation.admission_fenced",
        });
        await f.run();
        assertEquals(
            f.memory.get("development-7")?.pending === null,
            receipt.attempts === 0 &&
                receipt.result?.admission_fenced === true &&
                !("deployment_id" in receipt.result),
        );
        assertEquals(f.starts.length, 1);
    }
});

Deno.test("unrelated production and non-default checkouts with null history cannot block dev discovery", async () => {
    const f = fixture();
    f.listed.unshift({
        ...f.instance,
        id: 8,
        node_id: 4,
        source_layout: "release",
        auto_deploy: { ...f.instance.auto_deploy, history_version: null },
    });
    f.listed.push({
        ...f.instance,
        id: 9,
        name: "feature",
        auto_deploy: { ...f.instance.auto_deploy, history_version: null },
    });
    await f.run();
    assertEquals(f.starts.length, 1);
    assertEquals(
        f.requests.filter((r) => /instances\/(8|9)\//.test(r.path)),
        [],
    );
});

Deno.test("a default with no authoritative Route deploys even when its display Route is unrelated", async () => {
    const f = fixture();
    f.instance.route = { id: 999 };
    await f.run();
    assertEquals(f.starts.length, 1);
    assertEquals(f.requests.filter((r) => r.method === "PATCH"), []);
});
Deno.test("native-first deployment skips the old Gateway dev owner before reading new fields", async () => {
    const f = fixture();
    f.listed.splice(0, 1, {
        id: 7,
        node_id: 9,
        name: "default",
        status: "active",
        source_layout: "checkout",
        auto_deploy: { policy_owner: "gateway" },
    });
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(
        f.requests.some((r) => r.path === "/api/v1/instances/7"),
        false,
    );
});

Deno.test("Gateway development admissions and lost-response recovery use only development routes", async () => {
    for (const found of [true, false]) {
        const f = fixture();
        const paths: string[] = [];
        const operation = {
            ...f.operation,
            target_type: "instance",
            target_id: 7,
            attempts: 0,
            input: { commit: B, caller_node_id: 9 },
            caller_node_id: 9,
            error_code: null,
        };
        const client = new GatewayClient((method, path, body, key) => {
            if (!path.includes("/deploy/automatic")) {
                return f.http(method, path, body, key);
            }
            paths.push(path);
            const base = "/api/v1/instances/7/deploy/automatic/development";
            if (path === base) {
                if (paths.length === 1) throw new Error("Lost admission reply");
                return Promise.resolve({
                    status: 202,
                    body: { data: operation },
                });
            }
            const requestKey =
                (f.memory.get("development-7")?.pending as { key: string }).key;
            if (path.startsWith(base + "/receipt?")) {
                return Promise.resolve({
                    status: 200,
                    body: {
                        data: {
                            request_key: requestKey,
                            found,
                            operation: found ? operation : null,
                            deployment: null,
                        },
                    },
                });
            }
            assertEquals(path, base + "/resolve");
            return Promise.resolve({
                status: 200,
                body: {
                    data: {
                        request_key: requestKey,
                        found: true,
                        operation: {
                            ...operation,
                            status: "cancelled",
                            error_code: "operation.admission_fenced",
                            result: { admission_fenced: true },
                        },
                        deployment: null,
                    },
                },
            });
        });
        const run = (at = now) => client.development(f.ctx, at, [7], () => {});
        await assertRejects(() => run());
        assertEquals(
            (f.memory.get("development-7")?.pending as {
                operation_id: number | null;
            }).operation_id,
            null,
        );
        await run();
        assertEquals(paths.length, found ? 2 : 3);
        if (!found) {
            assertEquals(f.memory.get("development-7")?.pending, null);
            await run("2026-10-31T05:09:59.000Z");
            assertEquals(paths.length, 3);
            assertEquals(f.memory.get("development-7")?.result, "backing_off");
            await run("2026-10-31T05:10:00.000Z");
            assertEquals(paths.length, 4);
            assertEquals(f.memory.get("development-7")?.result, "started");
        }
        assertEquals(
            paths.every((path) => path.includes("/automatic/development")),
            true,
        );
    }
});

Deno.test("development policy refuses an enabled production Instance explicitly included in its input", async () => {
    const f = fixture();
    f.instance.source_layout = "release";
    f.instance.auto_deploy.development_eligible = false;
    assertEquals(f.instance.auto_deploy.eligible, true);

    await f.run(now, [7]);

    assertEquals(f.starts, []);
    assertEquals(f.memory.size, 0);
    assertEquals(f.requests.map((r) => r.path), ["/api/v1/instances"]);
});

Deno.test("a fresh production placement refuses a stale development selection", async () => {
    const f = fixture();
    f.listed.splice(0, 1, structuredClone(f.instance));
    f.instance.auto_deploy.development_eligible = false;
    assertEquals(f.instance.auto_deploy.eligible, true);

    await f.run(now, [7]);

    assertEquals(f.starts, []);
    assertEquals(f.memory.size, 0);
    assertEquals(f.requests.map((r) => r.path), [
        "/api/v1/instances",
        "/api/v1/instances/7",
    ]);
});

Deno.test("development policy never discovers work outside the Gateway selection", async () => {
    const f = fixture();
    await f.run(now, []);
    assertEquals(f.starts, []);
    assertEquals(f.memory.size, 0);
    assertEquals(f.requests.map((r) => r.path), ["/api/v1/instances"]);
});

Deno.test("fresh Gateway refusal stops a selected default before history or head reads", async () => {
    const f = fixture();
    f.listed.splice(0, 1, structuredClone(f.instance));
    f.instance.auto_deploy.eligible = false;
    f.instance.auto_deploy.development_eligible = false;
    f.instance.auto_deploy.history_version = null;
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.memory.size, 0);
    assertEquals(f.requests.map((r) => r.path), [
        "/api/v1/instances",
        "/api/v1/instances/7",
    ]);
});

Deno.test("canonical Gateway refusal skips ambiguous app-dev plus app-prod placement", async () => {
    const f = fixture();
    f.instance.auto_deploy.eligible = false;
    f.instance.auto_deploy.development_eligible = false;
    await f.run();
    assertEquals(f.starts, []);
    assertEquals(f.memory.size, 0);
});

Deno.test("three no-history failures escalate to twenty minutes and stop the head; a newer green head deploys", async () => {
    const f = fixture();
    f.instance.auto_deploy.gate = "Required checks";
    f.checks.push({
        name: "Required checks",
        head_sha: B,
        app: "github-actions",
        status: "completed",
        conclusion: "success",
    });
    for (
        const [index, at] of [
            now,
            "2026-10-31T05:10:00.000Z",
            "2026-10-31T05:30:00.000Z",
        ].entries()
    ) {
        f.operation.id = 100 + index;
        f.operation.status = "queued";
        await f.run(at);
        assertEquals(f.starts.length, index + 1);
        f.operation.status = "failed";
        await f.run(at);
        await f.run(at);
        assertEquals(f.starts.length, index + 1);
        assertEquals(
            f.memory.get("development-7")?.result,
            index === 2 ? "commit_failed" : "backing_off",
        );
        if (index === 1) {
            await f.run("2026-10-31T05:29:59.000Z");
            assertEquals(f.starts.length, 2);
        }
    }
    for (const at of ["2026-10-31T06:00:00.000Z", "2026-11-01T05:00:00.000Z"]) {
        await f.run(at);
    }
    assertEquals(f.starts.length, 3);
    assertEquals(f.history, []);
    f.source.head = "c".repeat(40);
    f.checks[0].head_sha = f.source.head;
    f.operation.status = "queued";
    await f.run("2026-11-01T05:01:00.000Z");
    assertEquals(f.starts.length, 4);
    assertEquals(f.starts[3].endsWith(f.source.head), true);
});
Deno.test("recorded and no-history failures share one ordinal and manual reset", async () => {
    const f = fixture();
    await f.run();
    f.operation.status = "failed";
    await f.run();
    f.history.push(failed(1));
    await f.run("2026-10-31T05:19:59.000Z");
    assertEquals(f.starts.length, 1);
    assertEquals(f.memory.get("development-7")?.result, "backing_off");
    await f.run("2026-10-31T05:20:00.000Z");
    assertEquals(f.starts.length, 2);
    f.operation.status = "failed";
    await f.run("2026-10-31T05:20:00.000Z");
    await f.run("2026-10-31T05:21:00.000Z");
    assertEquals(f.starts.length, 2);
    assertEquals(f.memory.get("development-7")?.result, "commit_failed");
    f.history.push(failed(2, true));
    await f.run("2026-10-31T05:22:00.000Z");
    assertEquals(f.starts.length, 3);
});
Deno.test("late history replaces no-history failure evidence without double counting", async () => {
    const f = fixture();
    await f.run();
    f.operation.status = "failed";
    f.operation.result = { deployment_id: 1 };
    await f.run();
    f.history.push(failed(1));
    await f.run("2026-10-31T05:10:00.000Z");
    assertEquals(f.starts.length, 2);
    assertEquals(f.memory.get("development-7")?.unrecorded_failures, []);
});
for (const green of [true, false]) {
    Deno.test(`old checkout success cannot declare a different container seed up to date (green ${green})`, async () => {
        const f = fixture();
        f.instance.auto_deploy.gate = "Required checks";
        f.checks.push({
            name: "Required checks",
            head_sha: B,
            app: "github-actions",
            status: "completed",
            conclusion: green ? "success" : "failure",
        });
        f.memory.set("development-7", {
            pending: {
                commit: B,
                key: "old-checkout",
                operation_id: 100,
                manual_after: 0,
            },
        });
        f.operation.status = "succeeded";
        await f.run();
        assertEquals(
            f.requests.filter((r) => r.method === "PUT").some((r) =>
                (r.body as { result: string }).result === "up_to_date"
            ),
            false,
        );
        assertEquals(
            f.memory.get("development-7")?.result,
            green ? "started" : "gate_failed",
        );
        assertEquals(f.starts.length, green ? 1 : 0);
    });
}
