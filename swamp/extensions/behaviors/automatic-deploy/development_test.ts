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
            development_retries: {} as Record<string, unknown>,
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
    const history: (Omit<Row, "status"> & { status: string })[] = [],
        starts: string[] = [],
        checks: Check[] = [];
    const writes: { name: string; version: number }[] = [];
    const operationReads: number[] = [];
    const operations = new Map<number, Operation>();
    const receipts = new Map<string, Operation>();
    let holderId: number | null = null;
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
            const active = holderId === null
                ? operation
                : operations.get(holderId)!;
            data = holder && path.endsWith(active.status)
                ? [{ ...active, target_type: "instance", target_id: 7 }]
                : [];
        } else if (path === "/api/v1/routes/3" && method === "PATCH") {
            data = { id: 200, status: repairStatus };
        } else if (path.endsWith("/policy") && method === "PUT") {
            if (
                (body as { route_operation_id?: number }).route_operation_id ===
                    200
            ) instance.development_projection_route_id = null;
            const ledger =
                (body as { development_retries?: Record<string, unknown> })
                    .development_retries;
            if (ledger) {
                instance.auto_deploy.development_retries = structuredClone(
                    ledger,
                );
            }
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
        receipt: (_id: number, _commit: string, key: string) => {
            receiptReads++;
            return Promise.resolve(
                structuredClone(receipts.get(key) ?? operation),
            );
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
                    : structuredClone(operations.get(id) ?? operation),
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
        admissions,
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
        operations,
        receipts,
        heldBy: (id: number) => {
            holderId = id;
            holder = true;
        },
        run,
        emitted,
        busy: () => {
            busy = true;
        },
        holder: (active = true) => {
            holder = active;
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
    f.history.push({
        ...failed(1),
        status: "interrupted",
        commit: null,
        failed_step: null,
        error_code: "operation.native_failed",
    });
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

Deno.test("all ended failure receipts settle and share bounded accounting", async () => {
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
        assertEquals(f.memory.get("development-7")?.pending, null);
        assertEquals(f.memory.get("development-7")?.result, "backing_off");
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

Deno.test("terminal cancellation settles even without admission-fence evidence", async () => {
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
        assertEquals(f.memory.get("development-7")?.pending, null);
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
    await f.run("2026-10-31T05:09:59.000Z");
    assertEquals(f.starts.length, 1);
    f.operation.status = "queued";
    await f.run("2026-10-31T05:10:00.000Z");
    assertEquals(f.starts.length, 2);
    f.operation.status = "failed";
    f.operation.result = { deployment_id: 1 };
    f.history.push({
        ...failed(1),
        commit: null,
        started_at: "2026-10-31T05:10:00.000Z",
        finished_at: "2026-10-31T05:10:00.000Z",
    });
    await f.run("2026-10-31T05:10:00.000Z");
    await f.run("2026-10-31T05:29:59.000Z");
    assertEquals(f.starts.length, 2);
    assertEquals(f.memory.get("development-7")?.result, "backing_off");
    f.operation.status = "queued";
    await f.run("2026-10-31T05:30:00.000Z");
    assertEquals(f.starts.length, 3);
    f.operation.status = "failed";
    f.operation.result = null;
    await f.run("2026-10-31T05:30:00.000Z");
    await f.run("2026-10-31T05:31:00.000Z");
    assertEquals(f.starts.length, 3);
    assertEquals(f.memory.get("development-7")?.result, "commit_failed");
    f.history.push({ ...failed(2, true), commit: null });
    await f.run("2026-10-31T05:32:00.000Z");
    assertEquals(f.starts.length, 4);
});
Deno.test("late history replaces retained failure only once it carries the pinned SHA", async () => {
    const f = fixture();
    await f.run();
    f.operation.status = "failed";
    f.operation.result = { deployment_id: 1 };
    await f.run();
    f.history.push({ ...failed(1), commit: null });
    await f.run("2026-10-31T05:09:59.000Z");
    assertEquals(f.starts.length, 1);
    assertEquals(
        (f.memory.get("development-7")?.unrecorded_failures as unknown[])
            .length,
        1,
    );
    f.history[0].commit = B;
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

// InstanceDeployProjection -> InstanceDeploymentRecorder -> InstanceDeploymentData.
// Null SHA variants are real retained container rows from before this fix.
const matrixOutcomes = [
    "success",
    "failure",
    "legacy-null-failure",
    "start-refusal",
    "queued-cancel",
    "running-cancel",
    "interruption",
    "timeout",
    "absent-admission",
    "paused",
    "replay",
    "refused-before-admission",
] as const;
const matrixIntents = ["none", "id", "key", "stale-id", "stale-key"] as const;
for (const outcome of matrixOutcomes) {
    for (const intent of matrixIntents) {
        if (
            outcome === "refused-before-admission" &&
            ["id", "stale-id"].includes(intent)
        ) continue;
        Deno.test(`matrix ${outcome} / ${intent}`, async () => {
            const variants = ["timeout", "start-refusal"].includes(outcome)
                ? ["none", "pinned"]
                : ["interruption", "running-cancel"].includes(outcome)
                ? ["null", "pinned"]
                : [outcome === "legacy-null-failure" ? "null" : "pinned"];
            for (const variant of variants) {
                const f = fixture();
                const stale = intent.startsWith("stale");
                const noEffect = ["paused", "success"].includes(outcome);
                const noRow = [
                    "queued-cancel",
                    "absent-admission",
                    "refused-before-admission",
                ].includes(outcome) ||
                    ["timeout", "start-refusal"].includes(outcome) &&
                        variant === "none";
                const status = outcome === "success"
                    ? "succeeded"
                    : outcome === "interruption"
                    ? "interrupted"
                    : [
                            "queued-cancel",
                            "running-cancel",
                            "absent-admission",
                            "refused-before-admission",
                        ].includes(outcome)
                    ? "cancelled"
                    : "failed";
                const error = outcome === "paused"
                    ? "deployment.paused"
                    : outcome === "timeout"
                    ? "operation.timed_out"
                    : outcome === "start-refusal"
                    ? "swamp.start_refused"
                    : ["absent-admission", "refused-before-admission"].includes(
                            outcome,
                        )
                    ? "operation.admission_fenced"
                    : outcome === "queued-cancel" || outcome === "success"
                    ? null
                    : "operation.native_failed";
                const settle = (index: number, at: string) => {
                    const key = `${
                        stale ? "old-checkout" : "dev.deploy"
                    }.${index}`;
                    const rowId = noRow ? null : index + 1;
                    Object.assign(f.operation, {
                        id: 100 + index,
                        status,
                        error_code: error,
                        attempts: [
                                "queued-cancel",
                                "absent-admission",
                                "refused-before-admission",
                                "start-refusal",
                            ].includes(outcome)
                            ? 0
                            : 1,
                        result: error === "operation.admission_fenced"
                            ? { admission_fenced: true }
                            : rowId === null
                            ? null
                            : { deployment_id: rowId },
                    });
                    if (rowId !== null) {
                        f.history.push({
                            id: rowId,
                            kind: "deploy",
                            status: status === "cancelled" ? "failed" : status,
                            commit: variant === "null" ? null : B,
                            triggered_by: "swamp:auto-deploy",
                            caller_node_id: 9,
                            failed_step: outcome === "start-refusal"
                                ? "admission"
                                : ["failure", "legacy-null-failure", "replay"]
                                        .includes(outcome)
                                ? "Check out in the container"
                                : null,
                            error_code: outcome === "running-cancel"
                                ? "deployment.cancelled"
                                : error,
                            started_at: at,
                            finished_at: at,
                        });
                    }
                    const state = f.memory.get("development-7") ?? {};
                    state.pending = intent === "none" ? null : {
                        commit: B,
                        key,
                        manual_after: 0,
                        operation_id: intent.endsWith("id")
                            ? f.operation.id
                            : null,
                    };
                    // No pending means settlement already durably saved the admission pin.
                    if (
                        intent === "none" && !noEffect &&
                        (noRow || variant === "null")
                    ) {
                        const retained =
                            (state.unrecorded_failures ?? []) as unknown[];
                        retained.push({
                            key,
                            commit: B,
                            deployment_id: rowId,
                            manual_after: 0,
                            finished_at: at,
                        });
                        state.unrecorded_failures = retained;
                    }
                    f.memory.set("development-7", state);
                };
                if (outcome === "success" && !stale) f.instance.seed_commit = B;
                const times = [
                    now,
                    "2026-10-31T05:10:00.000Z",
                    "2026-10-31T05:30:00.000Z",
                ];
                if (
                    outcome === "refused-before-admission" && intent === "none"
                ) {
                    await f.run();
                    assertEquals(f.starts.length, 1);
                } else if (noEffect) {
                    settle(0, now);
                    await f.run();
                    assertEquals(
                        f.memory.get("development-7")?.unrecorded_failures,
                        [],
                    );
                    assertEquals(
                        f.starts.length,
                        outcome === "success" && !stale ? 0 : 1,
                    );
                } else {
                    for (const [index, at] of times.entries()) {
                        settle(index, at);
                        await f.run(at);
                        await f.run(at);
                        assertEquals(
                            f.memory.get("development-7")?.pending,
                            null,
                        );
                        assertEquals(f.starts.length, index);
                        assertEquals(
                            f.memory.get("development-7")?.result,
                            index === 2 ? "commit_failed" : "backing_off",
                        );
                        if (index < 2) {
                            await f.run(
                                new Date(Date.parse(times[index + 1]) - 1)
                                    .toISOString(),
                            );
                            assertEquals(f.starts.length, index);
                            f.operation.status = "queued";
                            await f.run(times[index + 1]);
                            assertEquals(f.starts.length, index + 1);
                        }
                    }
                    await f.run("2026-11-01T05:00:00.000Z");
                    assertEquals(f.starts.length, 2);
                }
                const before = f.starts.length;
                f.source.head = "c".repeat(40);
                f.instance.auto_deploy.gate = "Required checks";
                f.checks.push({
                    name: "Required checks",
                    head_sha: f.source.head,
                    app: "github-actions",
                    status: "completed",
                    conclusion: "success",
                });
                settle(3, "2026-11-01T05:01:00.000Z");
                await f.run("2026-11-01T05:01:00.000Z");
                assertEquals(f.starts.length, before + 1);
                assertEquals(f.starts.at(-1)?.endsWith(f.source.head), true);
            }
        });
    }
}

for (const intent of ["id", "key"]) {
    Deno.test(`matrix reconciled-success / ${intent}`, async () => {
        const f = fixture();
        f.memory.set("development-7", {
            pending: {
                commit: B,
                key: "existing",
                operation_id: intent === "id" ? 100 : null,
                manual_after: 0,
            },
        });
        const reconcile = () => {
            f.instance.seed_commit = B;
            f.history.push({
                ...failed(1),
                status: "succeeded",
                error_code: null,
                failed_step: null,
            });
            return Promise.resolve({
                ...f.operation,
                status: "succeeded",
                result: { deployment_id: 1 },
            });
        };
        f.admissions.operation = reconcile;
        f.admissions.receipt = reconcile;
        await f.run();
        assertEquals(f.starts, []);
        assertEquals(f.memory.get("development-7")?.pending, null);
        assertEquals(f.memory.get("development-7")?.result, "up_to_date");
    });
}
for (
    const status of ["succeeded", "failed", "cancelled", "interrupted"] as const
) {
    Deno.test(`matrix original-command ${status} / stale-key`, async () => {
        const f = fixture();
        f.memory.set("development-7", {
            pending: {
                commit: A,
                key: "old-generic-key",
                operation_id: null,
                manual_after: 0,
            },
        });
        const oldOperation = {
            ...f.operation,
            status,
            attempts: status === "cancelled" ? 0 : 1,
            input: { commit: A, caller_node_id: 9 },
            target_type: "instance",
            target_id: 7,
            caller_node_id: 9,
            error_code: status === "cancelled" || status === "succeeded"
                ? null
                : "operation.native_failed",
            result: status === "cancelled" ? null : { deployment_id: 1 },
        };
        if (status !== "cancelled") {
            f.history.push({
                ...failed(1),
                status,
                commit: status === "succeeded" ? A : null,
                error_code: oldOperation.error_code,
                failed_step: null,
            });
        }
        const paths: string[] = [];
        const base = "/api/v1/instances/7/deploy/automatic";
        const client = new GatewayClient((method, path, body, key) => {
            if (!path.includes("/deploy/automatic")) {
                return f.http(method, path, body, key);
            }
            paths.push(path);
            if (method === "POST" && !path.endsWith("/resolve")) {
                assertEquals(path, base + "/development");
                return Promise.resolve({
                    status: 202,
                    body: {
                        data: {
                            ...oldOperation,
                            id: 200,
                            status: "queued",
                            error_code: null,
                            input: { commit: B, caller_node_id: 9 },
                            result: null,
                        },
                    },
                });
            }
            if (path.includes("/development/")) {
                return Promise.resolve({
                    status: 409,
                    body: {
                        error: {
                            code: "operation.idempotency_conflict",
                            message: "Original command differs",
                        },
                    },
                });
            }
            assertEquals(
                path,
                base + `/receipt?request_key=old-generic-key&commit=${A}`,
            );
            return Promise.resolve({
                status: 200,
                body: {
                    data: {
                        found: true,
                        request_key: "old-generic-key",
                        operation: oldOperation,
                        deployment: f.history[0] ?? null,
                    },
                },
            });
        });
        await client.development(f.ctx, now, [7], () => {});
        assertEquals(paths.length, 3);
        assertEquals(f.memory.get("development-7")?.result, "started");
        assertEquals(
            (f.memory.get("development-7")?.pending as { commit: string })
                .commit,
            B,
        );
    });
}

for (const intent of matrixIntents) {
    Deno.test(`matrix cancellation-request / ${intent}`, async () => {
        const f = fixture();
        f.operation.status = "running";
        f.holder();
        if (intent !== "none") {
            f.memory.set("development-7", {
                pending: {
                    commit: B,
                    key: "running-key",
                    operation_id: intent.endsWith("id") ? 100 : null,
                    manual_after: 0,
                },
            });
        }
        f.source.head = "c".repeat(40);
        await f.run();
        assertEquals(f.starts, []);
        assertEquals(
            f.memory.get("development-7")?.result,
            intent === "none" ? "busy" : "deploying",
        );
        f.operation.status = "cancelled";
        f.operation.result = { deployment_id: 1 };
        f.history.push({
            ...failed(1),
            error_code: "deployment.cancelled",
            failed_step: null,
        });
        await f.run();
        assertEquals(f.starts.length, 1);
        assertEquals(f.starts[0].endsWith(f.source.head), true);
    });
}

// Cross the eligibility/manual boundaries with the same five intent forms as
// the terminal matrix. No pending intent means there is no saved admission pin.
function matrixPending(intent: typeof matrixIntents[number]) {
    return intent === "none" ? null : {
        commit: B,
        key: intent.startsWith("stale") ? "old-checkout" : "dev.deploy.saved",
        operation_id: intent.endsWith("id") ? 100 : null,
        manual_after: 0,
    };
}
function greenHead(f: ReturnType<typeof fixture>, head = B) {
    f.source.head = head;
    f.instance.auto_deploy.gate = "Required checks";
    f.checks.length = 0;
    f.checks.push({
        name: "Required checks",
        head_sha: head,
        app: "github-actions",
        status: "completed",
        conclusion: "success",
    });
}
for (const intent of matrixIntents) {
    Deno.test(`matrix projection-unfinished / ${intent}`, async () => {
        for (
            const terminal of [
                "succeeded",
                "failed",
                "interrupted",
                "timeout",
            ] as const
        ) {
            const f = fixture();
            greenHead(f, "c".repeat(40));
            const pending = matrixPending(intent);
            f.memory.set("development-7", { pending });
            // Swamp is over, but projection has not completed: no history row
            // exists and the Gateway still reports a running Operation.
            f.operation.status = "running";
            f.holder();
            for (const at of [now, "2026-10-31T05:09:00.000Z"]) {
                await f.run(at);
                assertEquals(f.history, []);
                assertEquals(f.starts, []);
                assertEquals(
                    f.memory.get("development-7")?.result,
                    pending ? "deploying" : "busy",
                );
                assertEquals(
                    f.memory.get("development-7")?.unrecorded_failures,
                    [],
                );
                assertEquals(
                    f.memory.get("development-7")?.pending,
                    pending ? { ...pending, operation_id: 100 } : null,
                );
            }
            f.operation.status = terminal === "timeout" ? "failed" : terminal;
            f.operation.error_code = terminal === "timeout"
                ? "operation.timed_out"
                : terminal === "succeeded"
                ? null
                : "operation.native_failed";
            // Reconciliation creates the terminal history lazily; expiry alone
            // cannot create one. Timeout target hold is bounded by the Gateway.
            if (terminal !== "timeout") {
                f.operation.result = { deployment_id: 1 };
                f.history.push({
                    ...failed(1),
                    status: terminal,
                    commit: B,
                    failed_step: terminal === "failed"
                        ? "Check out in the container"
                        : null,
                    error_code: f.operation.error_code,
                });
                if (terminal === "succeeded") f.instance.seed_commit = B;
            }
            await f.run("2026-10-31T06:10:00.000Z");
            assertEquals(f.starts.length, 1);
            assertEquals(f.starts[0].endsWith(f.source.head), true);
        }
    });
    Deno.test(`matrix manual-superseded / ${intent}`, async () => {
        for (
            const status of [
                "succeeded",
                "failed",
                "interrupted",
                "cancelled",
            ] as const
        ) {
            for (const seedMatches of [false, true]) {
                const f = fixture();
                greenHead(f);
                const pending = matrixPending(intent);
                f.memory.set("development-7", {
                    pending,
                    unrecorded_failures: [{
                        key: "older-unrecorded",
                        commit: B,
                        deployment_id: null,
                        manual_after: 0,
                        finished_at: now,
                    }],
                });
                f.history.push(failed(1), failed(2), {
                    ...failed(3),
                    status: status === "cancelled" ? "failed" : status,
                    caller_node_id: 9,
                    failed_step: status === "failed"
                        ? "Check out in the container"
                        : null,
                    error_code: status === "cancelled"
                        ? "deployment.cancelled"
                        : status === "succeeded"
                        ? null
                        : "operation.native_failed",
                }, {
                    ...failed(4, true),
                    status: "succeeded",
                    caller_node_id: null,
                    commit: seedMatches ? B : A,
                    failed_step: null,
                    error_code: null,
                });
                f.instance.seed_commit = seedMatches ? B : A;
                Object.assign(f.operation, {
                    status,
                    attempts: 1,
                    error_code: status === "succeeded" || status === "cancelled"
                        ? null
                        : "operation.native_failed",
                    result: { deployment_id: 3 },
                });
                await f.run();
                assertEquals(
                    f.memory.get("development-7")?.unrecorded_failures,
                    [],
                );
                assertEquals(
                    f.memory.get("development-7")?.result,
                    seedMatches ? "up_to_date" : "started",
                );
                assertEquals(f.starts.length, seedMatches ? 0 : 1);
                const next = f.memory.get("development-7")?.pending as {
                    key: string;
                    manual_after: number;
                } | null;
                if (seedMatches) assertEquals(next, null);
                else {
                    assertExists(next);
                    assertEquals(next.manual_after, 4);
                    assertEquals(next.key === pending?.key, false);
                }
            }
        }
    });
    Deno.test(`matrix ineligible-before-execution / ${intent}`, async () => {
        for (
            const code of [
                "deployment.paused",
                "instance.auto_deploy_container_default_required",
                "instance.auto_deploy_role_inactive",
                "operation.execution_forbidden",
            ]
        ) {
            const f = fixture();
            greenHead(f);
            const pending = matrixPending(intent);
            f.memory.set("development-7", { pending });
            // StartSwampRun::fail records the worker refusal without history.
            Object.assign(f.operation, {
                status: "failed",
                attempts: 1,
                error_code: code,
                result: {
                    error: { code, message: "No longer eligible", details: {} },
                },
            });
            f.instance.auto_deploy.development_eligible = false;
            const saved = structuredClone(f.memory.get("development-7"));
            await f.run();
            assertEquals(f.memory.get("development-7"), saved);
            assertEquals(f.requests.map((r) => r.path), ["/api/v1/instances"]);
            assertEquals(f.history, []);
            assertEquals(f.starts, []);
            f.instance.auto_deploy.development_eligible = true;
            await f.run();
            const counted = pending !== null && code !== "deployment.paused";
            assertEquals(f.starts.length, counted ? 0 : 1);
            assertEquals(
                (f.memory.get("development-7")
                    ?.unrecorded_failures as unknown[]).length,
                counted ? 1 : 0,
            );
            if (counted) {
                await f.run("2026-10-31T05:09:59.999Z");
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "backing_off",
                );
                assertEquals(f.starts, []);
                f.operation.status = "queued";
                await f.run("2026-10-31T05:10:00.000Z");
                assertEquals(f.starts.length, 1);
            }
        }
    });
    Deno.test(`matrix ineligible-after-execution / ${intent}`, async () => {
        for (
            const terminal of [
                "succeeded",
                "failed",
                "runtime-removed",
            ] as const
        ) {
            const f = fixture();
            greenHead(f);
            const pending = matrixPending(intent);
            f.memory.set("development-7", { pending });
            f.operation.status = "running";
            f.holder();
            f.instance.auto_deploy.development_eligible = false;
            const saved = structuredClone(f.memory.get("development-7"));
            await f.run();
            assertEquals(f.memory.get("development-7"), saved);
            assertEquals(f.requests.map((r) => r.path), ["/api/v1/instances"]);
            // Disabling cannot cancel the native run. A surviving runtime can
            // still project its result. A removed runtime may prevent projection.
            if (terminal !== "runtime-removed") {
                f.operation.status = terminal;
                f.operation.error_code = terminal === "failed"
                    ? "operation.native_failed"
                    : null;
                f.operation.result = { deployment_id: 1 };
                f.history.push({
                    ...failed(1),
                    status: terminal,
                    failed_step: terminal === "failed"
                        ? "Check out in the container"
                        : null,
                    error_code: f.operation.error_code,
                });
                if (terminal === "succeeded") f.instance.seed_commit = B;
            }
            await f.run();
            assertEquals(f.memory.get("development-7"), saved);
            assertEquals(f.starts, []);
            // Restore eligibility. An unfinished projection still holds its pin
            // until the Gateway budget expires, even though Swamp has ended.
            f.instance.auto_deploy.development_eligible = true;
            if (terminal === "runtime-removed") {
                await f.run();
                assertEquals(
                    f.memory.get("development-7")?.result,
                    pending ? "deploying" : "busy",
                );
                assertEquals(
                    f.memory.get("development-7")?.pending,
                    pending ? { ...pending, operation_id: 100 } : null,
                );
                assertEquals(f.starts, []);
                Object.assign(f.operation, {
                    status: "failed",
                    error_code: "operation.timed_out",
                    result: { error_code: "operation.timed_out" },
                });
                assertEquals(f.history, []);
            }
            await f.run();
            const counted = terminal !== "succeeded" &&
                (pending !== null || terminal === "failed");
            assertEquals(
                f.starts.length,
                terminal === "runtime-removed" && !pending ? 1 : 0,
            );
            if (terminal === "succeeded") {
                assertEquals(f.memory.get("development-7")?.pending, null);
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "up_to_date",
                );
            } else if (counted) {
                await f.run("2026-10-31T05:09:59.999Z");
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "backing_off",
                );
                f.holder(false);
                f.operation.status = "queued";
                await f.run("2026-10-31T05:10:00.000Z");
                assertEquals(f.starts.length, 1);
            }
            const before = f.starts.length;
            greenHead(f, "c".repeat(40));
            f.operation.status = "failed";
            await f.run("2026-10-31T05:11:00.000Z");
            assertEquals(f.starts.length, before + 1);
            assertEquals(f.starts.at(-1)?.endsWith(f.source.head), true);
        }
    });
}

// This fixture is emitted and asserted by the real Gateway disable/enable API
// test in DevelopmentDeployPrecheckTest.php, including the actual history DTO.
const toggleCells = JSON.parse(
    Deno.readTextFileSync(
        new URL("./fixtures/development-toggle.json", import.meta.url),
    ),
) as {
    failures: number;
    disabled: { enabled: boolean; development_eligible: boolean; gate: null };
    enabled: {
        enabled: boolean;
        development_eligible: boolean;
        gate: null;
        resumed_after: number;
    };
    history: Row[];
}[];
async function realToggle(f: ReturnType<typeof fixture>, recorded: number) {
    const cell = toggleCells[recorded];
    const before = structuredClone(f.memory.get("development-7"));
    Object.assign(f.instance.auto_deploy, cell.disabled);
    await f.run();
    assertEquals(f.memory.get("development-7"), before);
    assertEquals(f.starts, []);
    Object.assign(f.instance.auto_deploy, cell.enabled);
}
for (const intent of matrixIntents) {
    for (const count of [1, 2, 3]) {
        for (const evidence of ["recorded", "mixed"] as const) {
            Deno.test(`matrix toggle-after-${count}-failures-${evidence} / ${intent}`, async () => {
                const f = fixture();
                const recorded = evidence === "recorded" ? count : count - 1;
                f.history.push(
                    ...structuredClone(toggleCells[recorded].history),
                );
                const pending = matrixPending(intent);
                f.memory.set("development-7", {
                    pending,
                    unrecorded_failures: !pending && evidence === "mixed"
                        ? [{
                            key: "settled-no-row",
                            commit: B,
                            deployment_id: null,
                            manual_after: 0,
                            finished_at: now,
                        }]
                        : [],
                });
                Object.assign(f.operation, {
                    status: "failed",
                    attempts: 1,
                    error_code: "operation.native_failed",
                    result: evidence === "recorded"
                        ? { deployment_id: count }
                        : null,
                });
                await realToggle(f, recorded);
                await f.run();
                // Settle a saved intent, then apply the same count on the next tick.
                await f.run();
                assertEquals(f.starts, []);
                assertEquals(f.memory.get("development-7")?.pending, null);
                assertEquals(
                    f.memory.get("development-7")?.result,
                    count === 3 ? "commit_failed" : "backing_off",
                );
                assertEquals(
                    (f.memory.get("development-7")
                        ?.unrecorded_failures as unknown[]).length,
                    evidence === "mixed" ? 1 : 0,
                );
                const due = count === 1
                    ? "2026-10-31T05:10:00.000Z"
                    : "2026-10-31T05:20:00.000Z";
                await f.run(
                    count === 1
                        ? "2026-10-31T05:09:59.999Z"
                        : "2026-10-31T05:19:59.999Z",
                );
                assertEquals(f.starts, []);
                f.operation.status = "queued";
                await f.run(due);
                assertEquals(f.starts.length, count === 3 ? 0 : 1);
                if (count === 3) {
                    await f.run("2026-11-01T05:00:00.000Z");
                    assertEquals(f.starts, []);
                }
                // A genuine manual row resets both retained and recorded failures.
                f.memory.set("development-7", {
                    ...f.memory.get("development-7"),
                    pending: null,
                });
                f.history.push({
                    ...failed(4, true),
                    caller_node_id: null,
                    status: "succeeded",
                    commit: A,
                    error_code: null,
                    failed_step: null,
                });
                f.operation.status = "queued";
                const beforeManual = f.starts.length;
                await f.run("2026-11-01T05:01:00.000Z");
                assertEquals(f.starts.length, beforeManual + 1);
                assertEquals(
                    f.memory.get("development-7")?.unrecorded_failures,
                    [],
                );
                assertEquals(
                    (f.memory.get("development-7")?.pending as {
                        manual_after: number;
                    }).manual_after,
                    4,
                );
            });
        }
    }
    for (const status of ["queued", "running"] as const) {
        Deno.test(`matrix toggle-${status === "queued" ? "pending" : "running"} / ${intent}`, async () => {
            const f = fixture();
            f.history.push(...structuredClone(toggleCells[2].history));
            const pending = matrixPending(intent);
            f.memory.set("development-7", { pending });
            Object.assign(f.operation, {
                status,
                attempts: status === "queued" ? 0 : 1,
            });
            f.holder();
            await realToggle(f, 2);
            await f.run();
            assertEquals(f.starts, []);
            assertEquals(
                f.memory.get("development-7")?.result,
                pending ? "deploying" : "busy",
            );
            assertEquals(
                f.memory.get("development-7")?.pending,
                pending ? { ...pending, operation_id: 100 } : null,
            );
            // The third failure has no row: worker refusal or expiry. It still
            // counts after a toggle, and a new green head remains independent.
            Object.assign(f.operation, {
                status: "failed",
                error_code: status === "queued"
                    ? "swamp.start_refused"
                    : "operation.timed_out",
                result: null,
            });
            f.holder(false);
            if (!pending) {
                // Without an intent the terminal Operation is not discoverable;
                // supply its real pinned history as the third failure instead.
                f.history.push(structuredClone(toggleCells[3].history[0]));
            }
            await f.run();
            await f.run("2026-10-31T06:30:00.000Z");
            assertEquals(f.starts, []);
            assertEquals(
                f.memory.get("development-7")?.result,
                "commit_failed",
            );
            assertEquals(
                (f.memory.get("development-7")
                    ?.unrecorded_failures as unknown[]).length,
                pending ? 1 : 0,
            );
            greenHead(f, "c".repeat(40));
            f.operation.status = "queued";
            await f.run("2026-10-31T06:31:00.000Z");
            assertEquals(f.starts.length, 1);
            assertEquals(f.starts[0].endsWith(f.source.head), true);
        });
    }
}

for (const intent of matrixIntents) {
    Deno.test(`matrix manual-running / ${intent}`, async () => {
        const f = fixture();
        const pending = matrixPending(intent);
        f.history.push(...structuredClone(toggleCells[2].history));
        f.memory.set("development-7", { pending });
        const previous = {
            ...f.operation,
            status: "failed",
            result: { deployment_id: 2 },
        };
        f.operations.set(100, previous);
        if (pending) f.receipts.set(pending.key, previous);
        // Development history is lazy: a running manual deploy has no row yet.
        const manual: Operation = {
            ...f.operation,
            id: 200,
            status: "running",
            triggered_by: "operator",
            caller_node_id: null,
        };
        f.operations.set(manual.id, manual);
        f.heldBy(manual.id);
        await f.run();
        await f.run();
        assertEquals(f.starts, []);
        assertEquals(f.memory.get("development-7")?.result, "busy");
        assertEquals(f.history.length, 2);
        assertEquals(f.memory.get("development-7")?.unrecorded_failures, []);
        // Only completed manual history resets the old automatic failures.
        manual.status = "succeeded";
        f.history.push({
            ...failed(3, true),
            status: "succeeded",
            commit: A,
            failed_step: null,
            error_code: null,
            caller_node_id: null,
        });
        f.holder(false);
        await f.run();
        assertEquals(f.starts.length, 1);
        assertEquals(
            (f.memory.get("development-7")?.pending as { manual_after: number })
                .manual_after,
            3,
        );
    });
    for (const stage of ["queued", "running", "succeeded"] as const) {
        Deno.test(`matrix interleaved-heads-${stage} / ${intent}`, async () => {
            const f = fixture();
            const C = "c".repeat(40), D = "d".repeat(40);
            greenHead(f, C);
            const pending = matrixPending(intent);
            const old: Operation = {
                ...f.operation,
                status: "failed",
                error_code: "operation.timed_out",
                result: null,
            };
            f.operations.set(old.id, old);
            if (pending) f.receipts.set(pending.key, old);
            f.memory.set("development-7", {
                pending,
                unrecorded_failures: pending ? [] : [{
                    key: "older-terminal",
                    commit: B,
                    deployment_id: null,
                    manual_after: 0,
                    finished_at: now,
                }],
            });
            const newer: Operation = {
                ...f.operation,
                id: 101,
                status: stage,
                input: { commit: C },
                result: stage === "succeeded" ? { deployment_id: 2 } : null,
            };
            f.operations.set(newer.id, newer);
            if (stage !== "succeeded") f.heldBy(newer.id);
            else {
                f.instance.seed_commit = C;
                f.history.push({
                    ...failed(2),
                    status: "succeeded",
                    commit: C,
                    error_code: null,
                    failed_step: null,
                });
            }
            await f.run();
            assertEquals(f.starts, []);
            assertEquals(
                f.memory.get("development-7")?.result,
                stage === "succeeded" ? "up_to_date" : "busy",
            );
            // After expiry, late projection starts history lazily but sees the
            // terminal Operation and returns without finishing that row. It
            // cannot replace retained pinned failure evidence with a running row.
            f.history.push({
                ...failed(1),
                status: "running",
                commit: null,
                finished_at: null,
                error_code: null,
                failed_step: null,
            });
            await f.run();
            assertEquals(f.starts, []);
            if (stage !== "succeeded") {
                f.holder(false);
                newer.status = "succeeded";
                newer.result = { deployment_id: 2 };
                f.instance.seed_commit = C;
                f.history.push({
                    ...failed(2),
                    status: "succeeded",
                    commit: C,
                    error_code: null,
                    failed_step: null,
                });
                await f.run();
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "up_to_date",
                );
            }
            greenHead(f, D);
            f.operation.id = 102;
            f.operation.status = "queued";
            f.operation.input.commit = D;
            await f.run();
            assertEquals(f.starts.length, 1);
            assertEquals(f.starts[0].endsWith(D), true);
            assertEquals(f.history.filter((r) => r.id === 1).length, 1);
            assertEquals(
                (f.memory.get("development-7")?.unrecorded_failures as {
                    commit: string;
                }[]).filter((r) => r.commit === B).length,
                1,
            );
        });
    }
    Deno.test(`matrix merge-check-changed / ${intent}`, async () => {
        for (const previous of [null, "Old checks"]) {
            const f = fixture();
            const pending = matrixPending(intent);
            f.memory.set("development-7", { pending });
            f.instance.auto_deploy.gate = previous;
            f.checks.push({
                name: "Old checks",
                head_sha: B,
                app: "github-actions",
                status: "completed",
                conclusion: "success",
            });
            // Observe the old configuration first, while quiet or already admitted.
            if (!pending) f.source.head = A;
            await f.run();
            assertEquals(
                f.memory.get("development-7")?.result,
                pending ? "deploying" : "up_to_date",
            );
            f.source.head = B;
            f.instance.auto_deploy.gate = "Required checks";
            await f.run();
            assertEquals(f.starts, []);
            assertEquals(
                f.memory.get("development-7")?.result,
                pending ? "deploying" : "gate_pending",
            );
            if (pending) {
                // A green verdict admitted earlier is frozen even if its rerun
                // turns red. The next selection reads the current gate again.
                f.checks.push({
                    name: "Required checks",
                    head_sha: B,
                    app: "github-actions",
                    status: "completed",
                    conclusion: "failure",
                });
                f.operation.status = "running";
                await f.run();
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "deploying",
                );
                assertEquals(
                    f.requests.filter((r) => r.path.includes("/checks?"))
                        .length,
                    0,
                );
                f.operation.status = "failed";
                await f.run();
                await f.run();
                assertEquals(
                    f.memory.get("development-7")?.result,
                    "gate_failed",
                );
                assertEquals(f.starts, []);
            }
        }
    });
}

Deno.test("durable retry limit survives fifty later automatic deployments", async () => {
    const f = fixture();
    f.history.push(failed(1), failed(2), failed(3));
    await f.run("2026-11-01T05:00:00.000Z");
    assertEquals(f.memory.get("development-7")?.result, "commit_failed");
    assertEquals(f.starts, []);
    // The real recorder retains only 50 rows. Fifty other automatic heads
    // finish, so the three B failures disappear without any manual deploy.
    f.history.splice(
        0,
        f.history.length,
        ...Array.from({ length: 50 }, (_, i) => ({
            ...failed(i + 4),
            commit: (i + 4).toString(16).padStart(40, "0"),
        })),
    );
    // The branch moves back to the same previously exhausted SHA B.
    await f.run("2026-11-02T05:00:00.000Z");
    assertEquals(f.starts, []);
    assertEquals(f.memory.get("development-7")?.result, "commit_failed");
});

for (const reset of [false, true]) {
    Deno.test(`matrix pruned-return-after-weeks / manual-reset-${reset}`, async () => {
        const f = fixture();
        f.history.push(failed(1), failed(2), failed(3));
        await f.run();
        const stopped = structuredClone(
            f.instance.auto_deploy.development_retries,
        );
        // Other green heads remain deployable despite B's stop.
        const C = "c".repeat(40);
        f.source.head = C;
        await f.run("2026-11-01T05:00:00.000Z");
        assertEquals(f.starts.length, 1);
        f.operation.status = "succeeded";
        f.instance.seed_commit = C;
        await f.run("2026-11-01T05:01:00.000Z");
        f.history.splice(
            0,
            f.history.length,
            ...Array.from({ length: 50 }, (_, i) => ({
                ...failed(i + 4),
                status: "succeeded",
                commit: (i + 4).toString(16).padStart(40, "0"),
            })),
        );
        f.source.head = B;
        await f.run("2026-12-20T05:00:00.000Z");
        assertEquals(f.starts.length, 1);
        assertEquals(f.memory.get("development-7")?.result, "commit_failed");
        assertEquals(
            (f.instance.auto_deploy.development_retries.commits as {
                commit: string;
                attempts: number;
            }[])
                .find((e) => e.commit === B)?.attempts,
            3,
        );
        if (reset) {
            f.history.push({
                ...failed(54, true),
                commit: C,
                status: "succeeded",
            });
            await f.run("2026-12-20T05:01:00.000Z");
            assertEquals(f.starts.length, 2);
            // Even after the manual row is pruned, its durable boundary remains.
            f.memory.delete("development-7");
            f.history.splice(
                0,
                f.history.length,
                ...Array.from({ length: 50 }, (_, i) => ({
                    ...failed(i + 55),
                    status: "succeeded",
                    commit: C,
                })),
            );
            await f.run("2027-01-20T05:00:00.000Z");
            assertEquals(f.starts.length, 3);
            assertEquals(
                f.instance.auto_deploy.development_retries.manual_after,
                54,
            );
        } else {
            // Losing/replaying Swamp checkpoints cannot lose the authoritative total.
            f.memory.clear();
            await f.run("2027-01-20T05:00:00.000Z");
            assertEquals(f.starts.length, 1);
            assertEquals(
                f.memory.get("development-7")?.result,
                "commit_failed",
            );
            assertEquals(
                f.instance.auto_deploy.development_retries.commits,
                stopped.commits,
            );
        }
    });
}

for (const intent of ["id", "key", "stale-id", "stale-key"]) {
    Deno.test(`matrix durable-duplicate-evidence / ${intent}`, async () => {
        const f = fixture();
        const checkpoint = {
            pending: {
                commit: B,
                key: "durable.duplicate",
                operation_id: intent.endsWith("id") ? 100 : null,
                manual_after: 0,
            },
        };
        f.operation.status = "failed";
        f.operation.result = { deployment_id: 1 };
        f.history.push(failed(1));
        if (intent.startsWith("stale")) {
            f.source.head = "c".repeat(40);
            f.operations.set(101, {
                ...f.operation,
                id: 101,
                status: "running",
            });
            f.heldBy(101);
        }
        f.memory.set("development-7", structuredClone(checkpoint));
        await f.run();
        // Replay the pending checkpoint against both retained row and receipt.
        f.memory.set("development-7", structuredClone(checkpoint));
        await f.run("2026-10-31T05:09:59.000Z");
        const entries = f.instance.auto_deploy.development_retries.commits as {
            attempts: number;
            last_failure_at: string;
            stopped: boolean;
        }[];
        assertEquals(entries[0].attempts, 1);
        assertEquals(entries[0].last_failure_at, now);
        assertEquals(entries[0].stopped, false);
        f.holder(false);
        f.source.head = B;
        await f.run("2026-10-31T05:10:00.000Z");
        assertEquals(f.starts.length, 1);
    });
}

Deno.test("matrix durable-late-reconciliation / unknown-history-id", async () => {
    const f = fixture();
    await f.run();
    f.operation.status = "failed";
    await f.run();
    // A timeout initially had no row. Reconciliation later attaches one to its receipt.
    f.operation.result = { deployment_id: 1 };
    f.history.push(failed(1));
    await f.run("2026-10-31T05:09:59.000Z");
    const entries = f.instance.auto_deploy.development_retries.commits as {
        attempts: number;
    }[];
    assertEquals(entries[0].attempts, 1);
    assertEquals(f.starts.length, 1);
    await f.run("2026-10-31T05:10:00.000Z");
    assertEquals(f.starts.length, 2);
});

Deno.test("matrix durable-checkpoint-replay / failed-write", async () => {
    const f = fixture();
    await f.run();
    f.operation.status = "failed";
    const write = f.ctx.writeResource;
    f.ctx.writeResource = () =>
        Promise.reject(new Error("Checkpoint unavailable"));
    await assertRejects(f.run);
    const entries = f.instance.auto_deploy.development_retries.commits as {
        attempts: number;
    }[];
    assertEquals(entries[0].attempts, 1);
    // The old pending checkpoint replays after the Gateway has already committed the count.
    f.ctx.writeResource = write;
    await f.run("2026-10-31T05:01:00.000Z");
    assertEquals(
        (f.instance.auto_deploy.development_retries.commits as {
            attempts: number;
        }[])[0].attempts,
        1,
    );
    await f.run("2026-10-31T05:09:59.000Z");
    assertEquals(f.starts.length, 1);
    await f.run("2026-10-31T05:10:00.000Z");
    assertEquals(f.starts.length, 2);
});

Deno.test("matrix durable-success-reset / replay-old-no-row-failure", async () => {
    const f = fixture();
    const checkpoint = {
        pending: {
            commit: B,
            key: "old.failed.no-row",
            operation_id: 100,
            manual_after: 0,
        },
    };
    f.operation.status = "failed";
    f.memory.set("development-7", checkpoint);
    await f.run();
    // Public ledger after a later, actually successful B deployment (Operation 101).
    // Its history was pruned before this replay of the older failed receipt.
    const entries = f.instance.auto_deploy.development_retries
        .commits as Record<string, unknown>[];
    Object.assign(entries[0], {
        attempts: 0,
        last_failure_at: null,
        stopped: false,
        evidence: [],
        reset_operation_id: 101,
        reset_after: 1,
        reset_at: "2026-10-31T05:02:00.000Z",
    });
    f.memory.set("development-7", checkpoint);
    await f.run("2026-10-31T05:20:00.000Z");
    assertEquals(
        (f.instance.auto_deploy.development_retries.commits as {
            attempts: number;
        }[])[0].attempts,
        0,
    );
    await f.run("2026-10-31T05:21:00.000Z");
    assertEquals(
        (f.instance.auto_deploy.development_retries.commits as {
            attempts: number;
        }[])[0].attempts,
        0,
    );
    assertEquals(f.starts.length, 1);
});
