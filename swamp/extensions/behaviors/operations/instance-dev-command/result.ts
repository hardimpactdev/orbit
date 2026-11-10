import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();
type Phase = z.infer<typeof Step> | null | undefined;
type Phases = {
    checkout: Phase;
    database: string;
    setup: string;
    processes: string[];
    route: string;
    /** The statuses of the Route's CSR and signing steps, which run beside the checkout. */
    certificates?: string[];
    t3_observation?: string;
    sessions_observation?: string;
    route_observation?: Record<string, unknown>;
    /** A container default's phases, which run instead of the checkout, the database and the setup. */
    container?: Container | null;
};
type Container = {
    launch: string;
    workspace: Phase;
    deploy: string[];
    processes: string;
    seed: string;
    /** A clone's phases: the agent's files, T3's first start and its registration. */
    agent?: string;
    t3?: string;
    register?: string;
    /** The registration step's receipt: the T3 server and the vault keys of its sessions. */
    server?: string | null;
};

/** The checkout phase prints the commit it left checked out as one `orbit-commit=<sha>` line. */
const COMMIT = /orbit-commit=([0-9a-f]{40}|[0-9a-f]{64})\b/;

/**
 * Map the step statuses to the terminal receipt: the checked-out commit, or the phase that failed.
 * A step that a failure skipped is not the failure, so a phase that ended `failed` wins over the first
 * phase that merely did not run. The Gateway already holds the rendered environment, the branch and the
 * Project's profile.
 */
export function createResult(intent: Record<string, unknown>, phases: Phases) {
    if (intent.already_active === true) {
        const result = {
            ready: true,
            instance_id: intent.instance_id,
            already_active: true,
        };
        return { ready: true, stdout: JSON.stringify(result) };
    }
    const container = phases.container ?? null;
    const source = container === null ? phases.checkout : container.workspace;
    const commit = source?.status === "succeeded"
        ? COMMIT.exec(String(source.outputs?.stdout ?? ""))?.[1] ?? null
        : null;
    const steps = z.array(z.object({ name: z.string() }).passthrough()).parse(
        intent.setup_steps,
    );
    const certificates = phases.certificates ?? [];
    // [phase, finished, failed]
    const outcomes: [string, boolean, boolean][] = container !== null
        ? containerOutcomes(intent, container, commit, phases)
        : [
        ["checkout", commit !== null, commit === null],
        // Only a create that copies the default database or owns a server database runs the database phase.
        [
            "database",
            (intent.database == null && intent.server_database == null) ||
            phases.database === "succeeded",
            phases.database === "failed",
        ],
        // A Project without setup steps runs no setup phase.
        [
            "setup",
            steps.length === 0 || phases.setup === "succeeded",
            phases.setup === "failed",
        ],
        [
            "processes",
            phases.processes.every((status) => status === "succeeded"),
            phases.processes.includes("failed"),
        ],
        // A Project that serves no web pages has no Route; its steps are skipped.
        [
            "route",
            intent.route == null || (phases.route === "succeeded" &&
                certificates.every((status) => status === "succeeded")),
            phases.route === "failed" || certificates.includes("failed"),
        ],
    ];
    const failed = outcomes.find(([, , failure]) => failure) ??
        outcomes.find(([, finished]) => !finished);
    const server = failed === undefined && container?.server ? JSON.parse(container.server) : null;
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(commit ? { commit } : {}),
        ...(server ? { t3: server } : {}),
        ...(failed
            ? {
                failed_step: failed[0],
                // A setup command keeps the public step-list code; T3 that never answers has its own; other phases name themselves.
                error_code: failed[0] === "setup"
                    ? "instance.setup_step_failed"
                    : failed[0] === "t3"
                    ? "instance.t3_unready"
                    : "instance.create_" + failed[0] + "_failed",
            }
            : {}),
    };
    if (phases.t3_observation || phases.sessions_observation) {
        const observed = (raw: string | undefined, key: string): string | null => {
            if (!raw) return null;
            try {
                const value = JSON.parse(raw)[key];
                return typeof value === "string" && Number.isFinite(Date.parse(value)) ? new Date(value).toISOString() : null;
            } catch { return null; }
        };
        const probeTimes: string[] = [];
        const collect = (value: unknown): void => {
            if (value === null || typeof value !== "object") return;
            const row = value as Record<string, unknown>;
            if (typeof row.finishedAt === "string" && Number.isFinite(Date.parse(row.finishedAt))) probeTimes.push(new Date(row.finishedAt).toISOString());
            for (const nested of Object.values(row)) if (nested !== null && typeof nested === "object") collect(nested);
        };
        for (const [name, value] of Object.entries(phases.route_observation ?? {})) if (name.startsWith("Probe the site over TLS")) collect(value);
        Object.assign(result, { readiness: {
            t3_answers_at: observed(phases.t3_observation, "ready_at"),
            sessions_checked_at: observed(phases.sessions_observation, "checked_at"),
            route_ready_at: probeTimes.sort().at(-1) ?? null,
        } });
    }
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}

/**
 * A container default's phases in order: the container, the workspace with its commit, each development deploy step, the
 * Processes, the Route and the seed snapshot. A clone has no deploy step and no seed: after the copy and the workspace come the
 * agent's files, the Processes, T3, the Route and the registration, whose receipt must name the server.
 */
function containerOutcomes(
    intent: Record<string, unknown>,
    container: Container,
    commit: string | null,
    phases: Phases,
): [string, boolean, boolean][] {
    const certificates = phases.certificates ?? [];
    const route: [string, boolean, boolean] = [
        "route",
        intent.route == null || (phases.route === "succeeded" &&
            certificates.every((status) => status === "succeeded")),
        phases.route === "failed" || certificates.includes("failed"),
    ];
    const clone = (intent.container as { clone?: unknown } | null)?.clone ?? null;
    if (clone !== null) {
        return [
            ["clone", container.launch === "succeeded", container.launch === "failed"],
            ["database", (intent.database == null && intent.server_database == null) || phases.database === "succeeded", phases.database === "failed"],
            ["workspace", commit !== null, container.workspace?.status === "failed"],
            ["agent", container.agent === "succeeded", container.agent === "failed"],
            ["processes", container.processes === "succeeded", container.processes === "failed"],
            ["t3", container.t3 === "succeeded", container.t3 === "failed"],
            route,
            [
                "register",
                container.register === "succeeded" && typeof container.server === "string",
                container.register === "failed",
            ],
        ];
    }
    return [
        ["container", container.launch === "succeeded", container.launch === "failed"],
        ["workspace", commit !== null, container.workspace?.status === "failed"],
        [
            "deploy",
            container.deploy.every((status) => status === "succeeded"),
            container.deploy.includes("failed"),
        ],
        ["processes", container.processes === "succeeded", container.processes === "failed"],
        [
            "route",
            intent.route == null || (phases.route === "succeeded" &&
                certificates.every((status) => status === "succeeded")),
            phases.route === "failed" || certificates.includes("failed"),
        ],
        ["seed", container.seed === "succeeded", container.seed === "failed"],
    ];
}
