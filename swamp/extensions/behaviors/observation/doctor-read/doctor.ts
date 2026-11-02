import { z } from "npm:zod@4.4.3";
import { inspectionResult } from "../../operations/firewall/inspection-result.ts";
import {
    FamilyReport,
    Input,
    type Issue,
    issue,
    Read,
    report,
} from "../doctor_contract.ts";
import { collectSignals } from "../doctor_signals.ts";

/** Public metadata only. Every argv becomes a separate native SSH exec step. */
export function planReads(intent: z.infer<typeof Input>): Read[] {
    const reads: Read[] = [];
    for (const node of intent.nodes) {
        if (!node.managed || node.parked) continue;
        if (!node.ssh) throw new Error("doctor.ssh_missing");
        const add = (
            family: Read["family"],
            resource: Read["resource"],
            check: Read["check"],
            argv: string[],
            sudo = false,
        ) => {
            reads.push({
                key: `read_${reads.length}`,
                node_id: node.id,
                ssh: node.ssh!,
                family,
                resource,
                check,
                argv,
                sudo,
            });
        };
        if (intent.families.includes("firewall") && node.platform === "linux") {
            add(
                "firewall",
                { id: `node:${node.id}`, name: node.name },
                "firewall",
                ["/usr/sbin/ufw", "status", "numbered"],
                true,
            );
        }
        if (intent.families.includes("node")) {
            add("node", node, "platform", ["uname", "-sm"]);
            add("node", node, "disk", ["df", "-Pk", "/"]);
            if (node.platform === "linux") {
                add("node", node, "agent", unit("orbit-agent.service"));
            }
        }
        if (intent.families.includes("role") && node.platform === "linux") {
            for (
                const role of node.roles.filter((r) => r.status === "active")
            ) {
                for (const name of role.services) {
                    add(
                        "role",
                        { id: role.id, name: role.role },
                        "service",
                        unit(name),
                    );
                }
            }
        }
        if (intent.families.includes("process") && node.platform === "linux") {
            for (const process of node.resources.processes) {
                add(
                    "process",
                    process,
                    process.runtime,
                    process.runtime === "systemd" ? unit(process.input.name) : [
                        "docker",
                        "container",
                        "ls",
                        "--all",
                        "--filter",
                        `name=^/${process.input.name}$`,
                        "--format",
                        "{{.Names}}\t{{.State}}",
                    ],
                    true,
                );
                if (
                    process.desired === "running" && !process.keep_alive &&
                    process.hibernation_path
                ) {
                    add("process", process, "awake", [
                        "test",
                        "-e",
                        process.hibernation_path,
                    ], true);
                }
            }
        }
    }
    return reads;
}
const unit = (
    name: string,
) => [
    "systemctl",
    "show",
    "--property=LoadState,ActiveState,SubState,NRestarts",
    "--",
    name,
];
const Output = z.object({
    exitCode: z.number().int(),
    stdout: z.string().max(1048576),
});
const Step = z.object({ status: z.string(), outputs: z.unknown().optional() });
const Results = z.record(z.string(), Step);
const Logs = z.object({
    cursor: z.object({
        path: z.string(),
        inode: z.number().int(),
        offset: z.number().int().nonnegative(),
    }).nullable(),
    signals: z.array(
        z.object({
            exception_class: z.string(),
            frame: z.string(),
            request_id: z.string().nullable(),
            recorded_at: z.string(),
        }),
    ),
});
function output(results: z.infer<typeof Results>, key: string) {
    const step = results[key], parsed = Output.safeParse(step?.outputs);
    return step?.status === "succeeded" && parsed.success ? parsed.data : null;
}
function properties(stdout: string): Record<string, string> {
    return Object.fromEntries(
        stdout.trim().split("\n").map((line) => {
            const separator = line.indexOf("=");
            if (separator < 1) throw new Error("doctor.unit_unavailable");
            return [line.slice(0, separator), line.slice(separator + 1)];
        }),
    );
}
const normalizeArchitecture = (name: string) =>
    ({ amd64: "x86_64", aarch64: "arm64" } as Record<string, string>)[name] ??
        name;

/** One typed comparison; no host calls, subprocesses, callbacks or hidden execution. */
export function compare(
    intent: z.infer<typeof Input>,
    results: z.infer<typeof Results>,
    now = Date.now() / 1000,
) {
    const reads = planReads(intent);
    const nodes = intent.nodes.map((node) => {
        const families = intent.families.map((family) => {
            const issues: Issue[] = [], checks: Record<string, string[]> = {};
            const blocked = new Map<string, Set<string>>();
            const selected = reads.filter((read) =>
                read.node_id === node.id && read.family === family &&
                read.check !== "awake"
            );
            const observed = (id: number | string, ...codes: string[]) => {
                checks[String(id)] = [
                    ...new Set([...(checks[String(id)] ?? []), ...codes]),
                ];
            };
            const fail = (read: Read) => {
                const key = String(read.resource.id),
                    codes = blocked.get(key) ?? new Set<string>();
                const tested = read.check === "service"
                    ? ["role.services_inactive"]
                    : read.check === "platform"
                    ? [
                        "node.platform_mismatch",
                        "node.architecture_mismatch",
                        "node.ssh_unreachable",
                    ]
                    : read.check === "disk"
                    ? ["node.disk_low"]
                    : read.check === "agent"
                    ? [
                        "node.agent_missing",
                        "node.agent_inactive",
                        "node.agent_view_stale",
                    ]
                    : [
                        "process.runtime_missing",
                        "process.crash_loop",
                        "process.state_mismatch",
                    ];
                tested.forEach((code) => codes.add(code));
                blocked.set(key, codes);
                if (
                    !issues.some((i) =>
                        i.code === `${family}.inspection_failed`
                    )
                ) {
                    issues.push(
                        issue(
                            family,
                            `${family}.inspection_failed`,
                            { id: `node:${node.id}`, name: node.name },
                            "One or more native reads did not return usable facts.",
                            "verifiable",
                            "unverifiable",
                            "unverifiable",
                        ),
                    );
                }
            };
            if (node.parked && family === "node") {
                issues.push(
                    issue(
                        family,
                        "node.task_vm_parked",
                        node,
                        "The task VM is parked.",
                        null,
                        "parked",
                        "informational",
                    ),
                );
            }
            if (node.managed && !node.parked && family === "node") {
                const status = node.resources.node_checks[0]?.status;
                if (status && status !== "active") {
                    issues.push(
                        issue(
                            family,
                            "node.lifecycle_not_active",
                            node,
                            "Node lifecycle is not active.",
                            "active",
                            status,
                        ),
                    );
                }
                observed(node.id, "node.lifecycle_not_active");
            }
            if (family === "role" && node.managed && !node.parked) {
                for (const role of node.roles) {
                    if (role.status !== "active") {
                        issues.push(
                            issue(
                                family,
                                "role.lifecycle_not_active",
                                { id: role.id, name: role.role },
                                "Role lifecycle is not active.",
                                "active",
                                role.status,
                            ),
                        );
                    }
                    observed(role.id, "role.lifecycle_not_active");
                }
            }
            if (family === "firewall" && node.managed && !node.parked) {
                for (const rule of node.resources.firewall_rules) {
                    if (rule.status !== "active") {
                        issues.push(
                            issue(
                                family,
                                "firewall.lifecycle_not_active",
                                rule,
                                "Firewall rule lifecycle is not active.",
                                "active",
                                rule.status,
                            ),
                        );
                    }
                    observed(rule.id, "firewall.lifecycle_not_active");
                }
            }
            for (const read of selected) {
                const value = output(results, read.key);
                if (!value || value.exitCode !== 0) {
                    fail(read);
                    continue;
                }
                try {
                    if (read.check === "firewall") {
                        const snapshot = inspectionResult({
                            stdout: value.stdout,
                            expected: node.resources.firewall_expected,
                        });
                        const resource = (name: string) =>
                            node.resources.firewall_rules.find((r) =>
                                r.name === name
                            ) ?? { id: `node:${node.id}`, name };
                        if (snapshot.backend !== "active") {
                            issues.push(
                                issue(
                                    family,
                                    "firewall.backend_inactive",
                                    read.resource,
                                    "UFW is inactive.",
                                    "active",
                                    snapshot.backend,
                                ),
                            );
                        }
                        for (
                            const row of snapshot.live.filter((r) =>
                                r.match === "drift"
                            )
                        ) {
                            issues.push(
                                issue(
                                    family,
                                    "firewall.rule_mismatch",
                                    resource(row.name),
                                    "Firewall rule differs from intent or has unsafe ordering.",
                                ),
                            );
                        }
                        for (const row of snapshot.missing) {
                            issues.push(
                                issue(
                                    family,
                                    "firewall.rule_missing",
                                    resource(row.name),
                                    "An intended firewall line is missing.",
                                    "present",
                                    "missing",
                                ),
                            );
                        }
                        observed(read.resource.id, "firewall.backend_inactive");
                        for (const rule of node.resources.firewall_rules) {
                            observed(
                                rule.id,
                                "firewall.rule_mismatch",
                                "firewall.rule_missing",
                            );
                        }
                    } else if (read.check === "platform") {
                        const [platform, architecture, ...extra] = value.stdout
                            .trim().split(/\s+/);
                        if (!platform || !architecture || extra.length) {
                            throw new Error("uname");
                        }
                        const expected = node.platform === "macos"
                            ? "Darwin"
                            : "Linux";
                        if (platform !== expected) {
                            issues.push(
                                issue(
                                    family,
                                    "node.platform_mismatch",
                                    node,
                                    "Node platform differs from intent.",
                                    expected,
                                    platform,
                                ),
                            );
                        }
                        if (
                            normalizeArchitecture(architecture) !==
                                normalizeArchitecture(node.architecture)
                        ) {
                            issues.push(
                                issue(
                                    family,
                                    "node.architecture_mismatch",
                                    node,
                                    "Node architecture differs from intent.",
                                    node.architecture,
                                    architecture,
                                ),
                            );
                        }
                        observed(
                            node.id,
                            "node.platform_mismatch",
                            "node.architecture_mismatch",
                            "node.ssh_unreachable",
                        );
                    } else if (read.check === "disk") {
                        const columns = value.stdout.trim().split("\n").at(-1)!
                            .trim().split(/\s+/);
                        const size = Number(columns[1]),
                            free = Number(columns[3]);
                        if (
                            columns.length < 6 || !Number.isFinite(size) ||
                            size <= 0 || !Number.isFinite(free) || free < 0 ||
                            free > size
                        ) throw new Error("df");
                        if (free < 2097152 || free < size / 10) {
                            issues.push(
                                issue(
                                    family,
                                    "node.disk_low",
                                    node,
                                    "The root filesystem has low free space.",
                                    "at least 2 GiB and 10% free",
                                    `${free} KiB free of ${size} KiB`,
                                ),
                            );
                        }
                        observed(node.id, "node.disk_low");
                    } else if (
                        read.check === "agent" || read.check === "service"
                    ) {
                        const state = properties(value.stdout);
                        if (!state.LoadState || !state.ActiveState) {
                            throw new Error("unit");
                        }
                        if (read.check === "agent") {
                            if (state.LoadState === "not-found") {
                                issues.push(
                                    issue(
                                        family,
                                        "node.agent_missing",
                                        node,
                                        "The agent service is missing.",
                                        "loaded",
                                        "not-found",
                                    ),
                                );
                            } else if (state.ActiveState !== "active") {
                                issues.push(
                                    issue(
                                        family,
                                        "node.agent_inactive",
                                        node,
                                        "The agent service is not active.",
                                        "active",
                                        state.ActiveState,
                                    ),
                                );
                            }
                            const view = node.observations;
                            if (
                                state.ActiveState === "active" &&
                                (view && (view.freshness !== "fresh" ||
                                    view.valid_until === null ||
                                    view.valid_until < now))
                            ) {
                                issues.push(
                                    issue(
                                        family,
                                        "node.agent_view_stale",
                                        node,
                                        "The Gateway has no fresh agent observation.",
                                        "fresh",
                                        "stale",
                                    ),
                                );
                            }
                            observed(
                                node.id,
                                "node.agent_missing",
                                "node.agent_inactive",
                                ...(view ? ["node.agent_view_stale"] : []),
                            );
                        } else {
                            if (
                                state.LoadState !== "loaded" ||
                                state.ActiveState !== "active"
                            ) {
                                issues.push(
                                    issue(
                                        family,
                                        "role.services_inactive",
                                        read.resource,
                                        "A role service is not active.",
                                        "active",
                                        `${
                                            read.argv.at(-1)
                                        }: ${state.LoadState}/${state.ActiveState}`,
                                    ),
                                );
                            }
                            observed(
                                read.resource.id,
                                "role.services_inactive",
                            );
                        }
                    } else {
                        const process = node.resources.processes.find((p) =>
                            p.id === read.resource.id
                        )!;
                        let state: string, missing = false, crash = false;
                        if (read.check === "systemd") {
                            const unit = properties(value.stdout);
                            if (
                                !unit.LoadState || !unit.ActiveState ||
                                !unit.SubState
                            ) throw new Error("unit");
                            missing = unit.LoadState === "not-found";
                            state = unit.ActiveState;
                            crash = process.desired === "running" &&
                                state === "activating" &&
                                unit.SubState === "auto-restart";
                        } else {
                            const lines = value.stdout.trim().split("\n")
                                .filter(Boolean);
                            if (lines.length > 1) {
                                throw new Error("container ambiguity");
                            }
                            missing = lines.length === 0;
                            const [name, status, ...extra] =
                                (lines[0] ?? "\tmissing").split("\t");
                            if (
                                !missing &&
                                (name !== process.input.name || !status ||
                                    extra.length)
                            ) throw new Error("container");
                            state = status;
                        }
                        if (missing) {
                            issues.push(
                                issue(
                                    family,
                                    "process.runtime_missing",
                                    process,
                                    "The managed Process runtime is missing.",
                                    "present",
                                    "absent",
                                ),
                            );
                        } else if (crash) {
                            issues.push(
                                issue(
                                    family,
                                    "process.crash_loop",
                                    process,
                                    "The Process is restarting after failure.",
                                    "running",
                                    "activating/auto-restart",
                                ),
                            );
                        } else {
                            const stopped = process.runtime === "systemd"
                                ? state === "inactive"
                                : ["created", "exited"].includes(state);
                            let healthy = process.desired === "stopped"
                                ? stopped
                                : state ===
                                    (process.runtime === "systemd"
                                        ? "active"
                                        : "running");
                            if (
                                !healthy && stopped &&
                                process.desired === "running" &&
                                !process.keep_alive && process.hibernation_path
                            ) {
                                const marker = reads.find((r) =>
                                    r.node_id === node.id &&
                                    r.resource.id === process.id &&
                                    r.check === "awake"
                                )!;
                                const awake = output(results, marker.key);
                                if (
                                    !awake || ![0, 1].includes(awake.exitCode)
                                ) throw new Error("awake marker");
                                healthy = awake.exitCode === 1;
                            }
                            if (!healthy) {
                                issues.push(
                                    issue(
                                        family,
                                        "process.state_mismatch",
                                        process,
                                        "Process state differs from intent.",
                                        process.desired,
                                        state,
                                    ),
                                );
                            }
                        }
                        observed(
                            process.id,
                            "process.runtime_missing",
                            "process.crash_loop",
                            "process.state_mismatch",
                        );
                    }
                } catch {
                    fail(read);
                }
            }
            // Never claim complete resource coverage: retired checks must not clear old incidents.
            for (const [key, codes] of Object.entries(checks)) {
                checks[key] = codes.filter((code) =>
                    !blocked.get(key)?.has(code)
                );
            }
            if (selected.length > 0 && blocked.size === 0) {
                observed(`node:${node.id}`, `${family}.inspection_failed`);
            }
            return report(family, selected.length, issues, checks);
        });
        return {
            node_id: node.id,
            node_name: node.name,
            healthy: families.every((f) => f.status === "healthy"),
            families,
        };
    });
    const issues = nodes.flatMap((n) => n.families.flatMap((f) => f.issues));
    const result: {
        report: unknown;
        logs?: z.infer<typeof Logs>;
        signals?: ReturnType<typeof collectSignals>;
    } = {
        report: {
            healthy: nodes.every((n) => n.healthy),
            nodes,
            summary: {
                nodes: nodes.length,
                families: nodes.reduce((n, row) => n + row.families.length, 0),
                checks: nodes.reduce(
                    (n, row) =>
                        n + row.families.reduce((sum, f) => sum + f.checked, 0),
                    0,
                ),
                drift: issues.filter((i) => i.kind === "drift").length,
                unverifiable:
                    issues.filter((i) => i.kind === "unverifiable").length,
                informational:
                    issues.filter((i) => i.kind === "informational").length,
            },
        },
    };
    if (intent.collect_problems) {
        const logs = output(results, "logs-0");
        if (logs?.exitCode === 0) {
            try {
                result.logs = Logs.parse(JSON.parse(logs.stdout));
            } catch { /* Preserve cursor on an unreadable log result. */ }
        }
        if (intent.signals) result.signals = collectSignals(intent.signals);
    }
    return result;
}
const Report = z.object({
    healthy: z.boolean(),
    nodes: z.array(
        z.object({
            node_id: z.number(),
            node_name: z.string(),
            healthy: z.boolean(),
            families: z.array(FamilyReport),
        }),
    ),
    summary: z.object({
        nodes: z.number(),
        families: z.number(),
        checks: z.number(),
        drift: z.number(),
        unverifiable: z.number(),
        informational: z.number(),
    }),
});
export const Result = z.object({
    report: Report,
    logs: Logs.optional(),
    signals: z.unknown().optional(),
});
export const behavior = {
    type: "@nckrtl/doctor",
    version: "2026.11.01.4",
    resources: {
        plan: {
            schema: z.object({ reads: z.array(Read), log_script: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: {
            schema: z.object({
                stdout: z.string(),
                exitCode: z.number().int(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        plan: {
            description:
                "List focused native SSH reads from public registry intent",
            arguments: z.object({ intent: Input }),
            execute: async (
                args: { intent: z.infer<typeof Input> },
                ctx: {
                    extensionFile(name: string): string;
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const log_script =
                    args.intent.collect_problems && args.intent.logs
                        ? (await Deno.readTextFile(
                            ctx.extensionFile("scripts/logs.py"),
                        )).replace(
                            '"__DOCTOR_LOG_INPUT__"',
                            JSON.stringify(JSON.stringify(args.intent.logs)),
                        )
                        : "";
                const value = { reads: planReads(args.intent), log_script };
                await ctx.writeResource("plan", "plan", value);
                return value;
            },
        },
        result: {
            description:
                "Compare native facts with registry intent and emit one typed report",
            arguments: z.object({ intent: Input, results: Results }),
            execute: async (
                args: {
                    intent: z.infer<typeof Input>;
                    results: z.infer<typeof Results>;
                },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const value = {
                    stdout: JSON.stringify(
                        Result.parse(compare(args.intent, args.results)),
                    ) + "\n",
                    exitCode: 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
