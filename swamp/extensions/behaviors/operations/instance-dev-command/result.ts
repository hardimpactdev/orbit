import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();

/**
 * The public part of a failed container or clone phase's result.json that the Operation keeps, or null. A refusal with a Node
 * service target keeps its code and target. A nested-fleet default refused while its Node records a failed nested-fleet image build
 * keeps its own code (docs/reference/development-containers.md#the-nested-fleet-image). Every other phase failure keeps the
 * phase's own code.
 */
export function containerFailure(raw: unknown): Record<string, unknown> | null {
    const target = z.object({ error_code: z.string().regex(/^[a-z]+\.[a-z0-9_]+$/), node_service_target: Public }).safeParse(raw);
    if (target.success) return target.data;
    const nested = z.object({ error_code: z.enum(["instance.nested_fleet_image_unavailable", ...CLUB_KEY_REFUSALS]) }).safeParse(raw);
    return nested.success ? nested.data : null;
}

/**
 * The public part of a nested-fleet clone's failed `fleet` phase, from the run's result.json, or null: the fleet phase's own code
 * (`instance.container_fleet_drift`, `instance.container_fleet_check_failed` or `instance.container_fleet_resume_failed`), why it
 * failed in the phase's own safe words (the Doctor body for a readiness wait), and each drift line. The Gateway redacts the detail again.
 */
export function fleetFailure(raw: unknown): Record<string, unknown> | null {
    const failure = z.object({
        error_code: z.string().regex(/^instance\.container_fleet_[a-z_]+$/),
        detail: z.string().optional(),
        fleet_drift: z.array(z.string()).optional(),
    }).safeParse(raw);
    if (!failure.success) return null;
    const { error_code, detail, fleet_drift } = failure.data;
    return { error_code, ...(detail ? { detail: detail.slice(0, 1000) } : {}), ...(fleet_drift ? { fleet_drift: fleet_drift.slice(0, 32) } : {}) };
}

/** The public codes of a nested-fleet start that refused to deliver its Club key, or failed to (docs/reference/development-containers.md#the-club-key-of-the-nested-gateway). */
const CLUB_KEY_REFUSALS = ["instance.nested_club_key_unsafe_transfer", "instance.nested_club_key_not_tmpfs", "instance.nested_club_key_delivery_failed"] as const;

/** The delivery script's words: a new key with serve restarted, a new key with serve left to start later, or the same key. */
const CLUB_KEY_WORDS = ["changed", "changed_serve_pending", "unchanged"] as const;
type ClubKeyWord = typeof CLUB_KEY_WORDS[number];

/**
 * What a nested-fleet start of this run did with its Club key, in the delivery script's own word, or null when nothing was written.
 * A default's container phase leaves the word in the run's result file; a clone's `club-key` step prints `{"phase":"club-key",
 * "club_key":…}`. The Gateway maps the word to the Operation's `nested_fleet_club_key`.
 */
function clubKeyWord(clone: boolean, step: Phase, file: unknown): ClubKeyWord | null {
    if (!clone) {
        const word = z.object({ club_key: z.enum(CLUB_KEY_WORDS) }).safeParse(file);
        return word.success ? word.data.club_key : null;
    }
    if (step?.status !== "succeeded") return null;
    try {
        const printed = z.object({ phase: z.literal("club-key"), club_key: z.enum(CLUB_KEY_WORDS) }).safeParse(JSON.parse(String(step.outputs?.stdout ?? "")));
        return printed.success ? printed.data.club_key : null;
    } catch {
        return null;
    }
}
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
    /** A nested-fleet default's result file from the run folder, which names what its start did with the Club key. */
    club_key_file?: unknown;
};
type Container = {
    mail?: Phase;
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
    /** A nested-fleet clone's `club-key` step. */
    club_key?: Phase;
    /** A nested-fleet clone's fleet start and readiness wait; skipped for every other clone. */
    fleet?: string;
};

/** The checkout phase prints the commit it left checked out as one `orbit-commit=<sha>` line. */
const COMMIT = /orbit-commit=([0-9a-f]{40}|[0-9a-f]{64})\b/;

/** The key names phpdotenv reads. */
const KEY = /^[A-Za-z0-9_.]+$/;

/**
 * A clone's workspace phase prints its JSON line with the NAMES of the keys it copied from its default's .env and the keys it
 * dropped (docs/reference/development-containers.md#the-environment-of-a-clone), the names it rewrote to the clone's own
 * host, and the names of the connections it shares with its default. Only names that are environment keys pass.
 */
export function cloneEnvironment(phase: Phase): {copied: string[]; dropped: string[]; rewritten?: string[]; shared?: string[]} | null {
    if (phase?.status !== "succeeded") return null;
    for (const line of String(phase.outputs?.stdout ?? "").split("\n")) {
        let parsed: unknown;
        try { parsed = JSON.parse(line); } catch { continue; }
        const printed = parsed as {phase?: unknown; environment?: unknown} | null;
        if (printed?.phase !== "workspace" || printed.environment == null) continue;
        const names = (raw: unknown) => Array.isArray(raw) ? raw.filter((name): name is string => typeof name === "string" && KEY.test(name)) : [];
        const value = printed.environment as {copied?: unknown; dropped?: unknown; rewritten?: unknown; shared?: unknown};
        // `rewritten`: the copied keys whose value pointed at the default's own site and now points at the clone's.
        // `shared`: the keys of the connections the clone shares with its default, rendered by Orbit as the clone's own.
        return {copied: names(value.copied), dropped: names(value.dropped), ...(Array.isArray(value.rewritten) ? {rewritten: names(value.rewritten)} : {}),
            ...(Array.isArray(value.shared) ? {shared: names(value.shared)} : {})};
    }
    return null;
}

/** Ansible emits only this bounded public vocabulary; no file contents or Process output is decoded. */
function mailResult(phase: Phase) {
    if (phase?.status !== "succeeded") return null;
    const found = /orbit-mail=(applied|unchanged|skipped|failed)(?::(mail_port_in_use|mail_unit_conflict|instance\.host_unavailable|process\.target_inactive|instance\.mail_update_failed))?/.exec(String(phase.outputs?.stdout ?? ""));
    if (!found || (["skipped", "failed"].includes(found[1]) && !found[2])) return null;
    if (found[1] === "failed" && !["instance.host_unavailable", "process.target_inactive", "instance.mail_update_failed"].includes(found[2])) return null;
    if (found[1] === "skipped" && !["mail_port_in_use", "mail_unit_conflict"].includes(found[2])) return null;
    return {status: found[1], ...(found[2] ? {reason: found[2]} : {}), ports: [1025, 2525]};
}

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
    const mail = (intent.container as {mail?: unknown} | null)?.mail != null
        ? mailResult(container?.mail) ?? {status: "failed", reason: "instance.mail_update_failed", ports: [1025, 2525]}
        : null;
    const environment = (intent.container as {clone?: unknown} | null)?.clone != null ? cloneEnvironment(container?.workspace) : null;
    const nested = (intent.container as {club_key?: unknown} | null | undefined)?.club_key != null;
    const club = nested ? clubKeyWord((intent.container as {clone?: unknown}).clone != null, container?.club_key, phases.club_key_file) : null;
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(commit ? { commit } : {}),
        ...(container !== null && (intent.container as {clone?: unknown} | null)?.clone == null
            ? {node_pin: {status: "not_prepared", reason: "container_from_stock_image"}} : {}),
        ...(server ? { t3: server } : {}),
        ...(environment ? {environment} : {}),
        ...(mail ? {mail} : {}),
        ...(club ? {club_key: club} : {}),
        ...(mail?.status === "failed" ? {warnings: [{code: "instance.mail_update_failed", step: "mail"}]} : {}),
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
 * Processes, the Route and the seed snapshot. A clone has no deploy step and no seed: after the copy (and a nested-fleet clone's
 * fleet) come the workspace, the agent's files, the Processes, T3, the Route and the registration, whose receipt must name the
 * server. Agent and T3 run beside the workspace, so this order, not the run's, names the failed phase when several fail.
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
            // Only a clone of a nested-fleet default has `fleet` in its intent and runs this phase.
            ["fleet", intent.fleet == null || container.fleet === "succeeded", container.fleet === "failed"],
            ["database", (intent.database == null && intent.server_database == null && intent.server_valkey == null) || phases.database === "succeeded", phases.database === "failed"],
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
