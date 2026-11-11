import { GatewayClient } from "./gateway.ts";
import { type Gateway, type Operation, StateSchema, tick } from "./policy.ts";

const [A, B, C] = ["a", "b", "c"].map((c) => c.repeat(40));
const now = "2026-11-01T12:00:00.000Z";
const key = `auto.7.${B}.1`;
const outcomes = [
    ["success", "succeeded", "succeeded", null, null, 1],
    ["failure", "failed", "failed", "preparation", "source.unavailable", 1],
    [
        "lasting-failure",
        "failed",
        "failed",
        "deploy",
        "deployment.build_failed",
        1,
    ],
    ["start-refusal", "failed", "failed", "admission", "env.operation_busy", 1],
    ["queued-cancel", "cancelled", "failed", null, null, 0],
    ["cancellation-request", "running", "running", null, null, 1],
    ["projection-unfinished", "running", "running", null, null, 1],
    [
        "running-cancel",
        "cancelled",
        "failed",
        "operation",
        "deployment.cancelled",
        1,
    ],
    [
        "interruption",
        "interrupted",
        "interrupted",
        "operation",
        "operation.native_failed",
        1,
    ],
    ["timeout", "failed", "failed", "operation", "operation.timed_out", 1],
    [
        "absent-admission",
        "cancelled",
        null,
        null,
        "operation.admission_fenced",
        0,
    ],
    ["paused", "failed", "failed", "admission", "deployment.paused", 1],
] as const;
type ProjectedCell = {
    receipt: Record<string, unknown>;
    history: Record<string, unknown>;
};
const projected = JSON.parse(
    await Deno.readTextFile(
        new URL(
            "../../../../apps/gateway/tests/Fixtures/production-deploy-orbit-main.json",
            import.meta.url,
        ),
    ),
) as Record<string, ProjectedCell>;
const normalized = (value: unknown, field = ""): unknown => {
    if (
        [
            "id",
            "target_id",
            "instance_id",
            "node_id",
            "caller_node_id",
            "deployment_id",
        ].includes(field)
    ) {
        if (value === "operation") return 100;
        if (value === "history") return 2;
        if (value === "instance") return 7;
        if (value === "node") return 9;
    }
    if (Array.isArray(value)) return value.map((child) => normalized(child));
    if (value && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value).map((
                [key, child],
            ) => [key, normalized(child, key)]),
        );
    }
    return value;
};
const intents = ["none", "id", "key", "stale-id", "stale-key"] as const;

/** Same fixture executes unchanged on orbit-main and the candidate. All reads use production routes. */
export async function productionGuard() {
    const output: Record<string, unknown> = {};
    const cells: (readonly [
        string,
        string,
        string | null,
        string | null,
        string | null,
        number,
    ])[] = [
        ...outcomes,
        [
            "legacy-null-failure",
            "failed",
            "failed",
            "operation",
            "operation.native_failed",
            1,
        ],
        ["replay", "failed", "failed", "preparation", "source.unavailable", 1],
        [
            "refused-before-admission",
            "failed",
            null,
            null,
            "swamp.start_refused",
            0,
        ],
        ...Object.entries(projected).filter(([, cell]) =>
            cell.receipt && cell.history
        ).map((
            [label, cell],
        ): [
            string,
            string,
            string | null,
            string | null,
            string | null,
            number,
        ] => [
            `projected:${label}`,
            cell.receipt.status as string,
            cell.history.status as string,
            cell.history.failed_step as string | null,
            cell.receipt.error_code as string | null,
            cell.receipt.attempts as number,
        ]),
    ];
    for (const [label, status, rowStatus, step, code, attempts] of cells) {
        const real = label.startsWith("projected:")
            ? normalized(projected[label.slice(10)]) as ProjectedCell
            : null;
        // Production creates its row on admission. No-row covers cancellation/fencing before admission.
        const forms = real
            ? real.history.status === "failed" &&
                    real.history.commit !== null &&
                    real.history.error_code !== "deployment.paused"
                ? ["projected", "retry2", "retry3"]
                : ["projected"]
            : rowStatus === null
            ? ["absent"]
            : ["failure", "replay"].includes(label)
            ? ["pinned", "unpinned", "absent", "retry2", "retry3"]
            : ["pinned", "unpinned", "absent"];
        for (const form of forms) {
            for (const intent of intents) {
                const commit = B;
                const requestKey = key;
                const history = [
                    {
                        id: 1,
                        kind: "deploy",
                        status: "succeeded",
                        commit: A,
                        triggered_by: "laptop",
                        caller_node_id: null,
                        failed_step: null,
                        error_code: null,
                        started_at: now,
                        finished_at: now,
                    },
                    ...(form === "absent" ? [] : [{
                        id: 2,
                        kind: "deploy",
                        status: rowStatus,
                        commit: form === "pinned" || form.startsWith("retry")
                            ? commit
                            : null,
                        triggered_by: "orbit:auto-deploy",
                        caller_node_id: 9,
                        failed_step: step,
                        error_code: code,
                        started_at: now,
                        finished_at: rowStatus === "running" ? null : now,
                    }]),
                ];
                const operation = {
                    id: 100,
                    target_type: "instance",
                    target_id: 7,
                    kind: "instance.deploy",
                    status,
                    attempts,
                    input: { commit, caller_node_id: 9 },
                    triggered_by: "orbit:auto-deploy",
                    caller_node_id: 9,
                    result: label === "absent-admission"
                        ? { admission_fenced: true }
                        : form === "absent"
                        ? null
                        : { deployment_id: 2 },
                    error_code: code,
                };
                if (real) {
                    Object.assign(history[1], real.history);
                    Object.assign(operation, real.receipt);
                }
                if (form.startsWith("retry")) {
                    for (let id = 3; id <= Number(form.slice(5)) + 1; id++) {
                        history.push({ ...history[1], id });
                    }
                }
                const receipt = {
                    request_key: requestKey,
                    found: true,
                    operation,
                    deployment: form === "absent" ? null : history[1],
                };
                const starts: { commit: string; key: string }[] = [];
                const requests: string[] = [];
                const operations = new Map<number, typeof operation>([[
                    100,
                    operation,
                ]]);
                let holder: typeof operation | null = status === "running"
                    ? operation
                    : null;
                const client = new GatewayClient(
                    async (method, path, body, admittedKey) => {
                        requests.push(`${method} ${path}`);
                        let data: unknown;
                        if (path.endsWith("/deployments")) data = history;
                        else if (
                            path.includes("/receipt?") ||
                            path.endsWith("/resolve")
                        ) data = receipt;
                        else if (path.startsWith("/api/v1/operations/")) {
                            data = operations.get(
                                Number(path.split("/").at(-1)),
                            );
                        } else if (
                            method === "POST" && path.endsWith("/automatic")
                        ) {
                            const admitted =
                                (body as { commit: string }).commit;
                            starts.push({
                                commit: admitted,
                                key: admittedKey!,
                            });
                            data = {
                                ...operation,
                                id: 101,
                                status: "queued",
                                input: { commit: admitted, caller_node_id: 9 },
                                result: null,
                            };
                            holder = data as typeof operation;
                            operations.set(holder.id, holder);
                        } else {throw new Error(
                                `Unexpected production route ${method} ${path}`,
                            );}
                        return { status: 200, body: { data } };
                    },
                );
                let head = intent.startsWith("stale") ? C : B;
                const gateway: Gateway = {
                    observe: async () => ({
                        enabled: true,
                        production: true,
                        active: true,
                        gate: null,
                        repository: "acme/shop",
                        gateReadable: true,
                        resumedAfter: 1,
                        blocked: [],
                        history: await client.history(7),
                        holder,
                        historyVersion: "f".repeat(64),
                        policyOwner: "swamp",
                    }),
                    history: (id) => client.history(id),
                    head: () => Promise.resolve(head),
                    walk: () =>
                        Promise.resolve({
                            head,
                            commits: [A, B, C].map((sha, index, all) => ({
                                sha,
                                parents: index ? [all[index - 1]] : [],
                                descendsFromDeployed: index > 0,
                            })),
                        }),
                    checks: () => Promise.resolve([]),
                    deploy: (id, sha, request) =>
                        client.deploy(id, sha, request),
                    operation: (id) => client.operation(id),
                    receipt: (id, sha, request) =>
                        client.receipt(id, sha, request),
                    resolve: (id, sha, request) =>
                        client.resolve(id, sha, request),
                };
                let state = StateSchema.parse({
                    intent: intent === "none" ? null : {
                        commit,
                        key: requestKey,
                        attempt: 1,
                        manual_after: 1,
                        operation_id: intent.endsWith("id") ? 100 : null,
                    },
                });
                const results: unknown[] = [];
                for (
                    const [minutes, newer] of [[0, false], [10, false], [
                        20,
                        false,
                    ], [
                        30,
                        true,
                    ]] as const
                ) {
                    if (newer) head = C;
                    try {
                        const decision = await tick(
                            7,
                            state,
                            gateway,
                            (next) => {
                                state = structuredClone(next);
                                return Promise.resolve();
                            },
                            new Date(Date.parse(now) + minutes * 60_000)
                                .toISOString(),
                        );
                        results.push({
                            result: decision?.state.result ?? null,
                            commit: decision?.state.commit ?? null,
                            error: decision?.state.error_code ?? null,
                            effect_hold: state.effect_hold,
                            intent: state.intent,
                            attempts: state.attempts,
                            blocked: state.blocked,
                            starts: structuredClone(starts),
                            history: structuredClone(history),
                        });
                    } catch (error) {
                        results.push({
                            error: error instanceof Error && "code" in error
                                ? error.code
                                : error instanceof Error
                                ? error.name
                                : String(error),
                            starts: structuredClone(starts),
                        });
                    }
                }
                // Pin the actual decoder separately, including receipts whose deployment row is interrupted.
                let decodedReceipt: unknown;
                try {
                    decodedReceipt = await client.receipt(
                        7,
                        commit,
                        requestKey,
                    );
                } catch (error) {
                    decodedReceipt = {
                        error: error instanceof Error && "code" in error
                            ? error.code
                            : "error",
                    };
                }
                output[`${label}/${form}/${intent}`] = {
                    receipt: decodedReceipt,
                    results,
                    requests,
                };
            }
        }
    }
    const snapshots: Record<string, unknown> = {};
    for (const [cell, value] of Object.entries(output)) {
        // The digest pins complete receipts, history, state, requests and all decisions.
        // Keep a short readable verdict alongside it for review.
        const digest = await crypto.subtle.digest(
            "SHA-256",
            new TextEncoder().encode(JSON.stringify(value)),
        );
        const record = value as {
            results: { result?: string; error?: string | null }[];
        };
        snapshots[cell] = {
            sha256: Array.from(
                new Uint8Array(digest),
                (byte) => byte.toString(16).padStart(2, "0"),
            ).join(""),
            decisions: record.results.map((r) => r.result ?? r.error),
        };
    }
    // This module is deliberately unchanged from orbit-main. Any future shared-policy
    // widening requires an explicit production change and golden review.
    const sourceDigest = await crypto.subtle.digest(
        "SHA-256",
        await Deno.readFile(new URL("./policy.ts", import.meta.url)),
    );
    snapshots["$production-policy-source"] = {
        sha256: Array.from(
            new Uint8Array(sourceDigest),
            (byte) => byte.toString(16).padStart(2, "0"),
        ).join(""),
    };
    return snapshots;
}
