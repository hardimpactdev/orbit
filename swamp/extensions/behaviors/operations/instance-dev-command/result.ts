import { z } from "npm:zod@4.4.3";
export const Public = z.record(z.string(), z.unknown());
export const Step = z.object({ status: z.string(), outputs: Public.optional() })
    .passthrough();

/**
 * The arguments of a create's result: the run folder, the intent and each phase's step. `finish` takes them, and a ready clone's
 * `clone-register` takes the same map (one YAML anchor in workflow-operation-instance-create.yaml), so both build one result.
 * `t3_file`: where `clone-session-copy` put a clone's `t3.json` (docs/cli/instance/container/create.mmd).
 */
export const CreateArgs = z.object({
    intent: Public,
    folder: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+$/),
    t3_file: z.string().regex(/^\/tmp\/orbit-ansible-[A-Za-z0-9-]+-t3\.json$/).optional(),
    checkout: Step.nullable(),
    database: z.string(),
    setup: z.string(),
    processes: z.array(z.string()),
    route: z.string(),
    certificates: z.array(z.string()).default([]),
    t3_observation: z.string().default(""),
    route_observation: Public.default({}),
    container: z.object({
        mail: Step.nullable().default(null),
        launch: z.string(),
        workspace: Step.nullable(),
        deploy: z.array(z.string()),
        processes: z.string(),
        seed: z.string(),
        t3: z.string().default("skipped"),
        register: z.string().default("skipped"),
        server: z.string().nullable().default(null),
        club_key: Step.nullable().default(null),
        ci_hold: Step.nullable().default(null),
    }).nullable().default(null),
});

/**
 * Whether a site step of a clone's publication refused its merge on a Node and left its mark (route-create-site.yml) in the run folder.
 * Both result builders, `finish` and a ready clone's `clone-register`, read it before the run folder goes.
 */
export async function routeDeferred(intent: Record<string, unknown>, route: string, folder: string): Promise<boolean> {
    if (route !== "failed" || (intent.container as {clone?: unknown} | null | undefined)?.clone == null) return false;
    try {
        for await (const entry of Deno.readDir(folder)) {
            if (entry.isFile && /^route-deferred-[0-9]+\.json$/.test(entry.name)) return true;
        }
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) return false;
        throw error;
    }
    return false;
}

/**
 * Removes a create's run folder and the copied `t3.json` beside it (either may hold a clone's checked T3 sessions); true when both
 * are gone, also when one never existed. Any other failure leaves them to the cleanup step.
 */
export async function removeRun(folder: string, t3File?: string): Promise<boolean> {
    let gone = true;
    for (const path of [folder, ...(t3File === undefined ? [] : [t3File])]) {
        try {
            await Deno.remove(path, { recursive: true });
        } catch (error) {
            gone &&= error instanceof Deno.errors.NotFound;
        }
    }
    return gone;
}

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
 * The public part of a container default's failed `container` phase, from the run's result.json, or null: what containerFailure
 * keeps, else the phase's own code (a refusal's, or the generic `instance.container_container_failed`) and its detail, the failed task
 * with its message and the end of its stderr. A detail above 4096 characters keeps its head, which names the task, and its tail. The
 * command step keeps no stderr of a failed playbook, so the Gateway records the detail as the failure's stderr and redacts it again.
 */
export function defaultContainerFailure(raw: unknown): Record<string, unknown> | null {
    const kept = containerFailure(raw);
    const failure = z.object({ error_code: z.string().regex(/^[a-z]+\.[a-z0-9_]+$/), detail: z.string().optional() }).safeParse(raw);
    if (!failure.success) return kept;
    const { error_code, detail } = failure.data;
    const bounded = detail === undefined || detail.length <= 4096 ? detail : detail.slice(0, 1000) + "\n...\n" + detail.slice(-(4096 - 1005));
    return { ...(kept ?? { error_code }), ...(bounded ? { detail: bounded } : {}) };
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
    route_observation?: Record<string, unknown>;
    /** A container default's phases, which run instead of the checkout, the database and the setup. */
    container?: Container | null;
    /** A nested-fleet default's result file from the run folder, which names what its start did with the Club key. */
    club_key_file?: unknown;
    /** The part of a clone's T3 step that ran when it failed or timed out, from the step's `t3.json` in the run folder. */
    t3_part?: "agent" | "t3" | "checkout" | "sessions" | null;
    /** A clone's merge of its Caddy groups refused on a Node and changed nothing: the site step left its mark in the run folder. */
    route_deferred?: boolean;
};
type Container = {
    mail?: Phase;
    launch: string;
    workspace: Phase;
    deploy: string[];
    processes: string;
    seed: string;
    /** A clone's phases: the T3 step (the agent's files, then T3's first start) and its registration. */
    t3?: string;
    register?: string;
    /** The registration step's receipt: the T3 server and the vault keys of its sessions. */
    server?: string | null;
    /** A nested-fleet clone's `club-key` step. */
    club_key?: Phase;
    /** A proof copy's `clone-ci-hold` step. */
    ci_hold?: Phase;
};

/**
 * The line that a proof copy's `clone-ci-hold` prints when its Node has no hold script of the `ci-runner` role: the step calls nothing,
 * and the create fails with `instance.ci_hold_unavailable` (docs/reference/node-provisioning.md#proof-places-on-a-ci-node).
 */
export const CI_HOLD_UNAVAILABLE = "orbit-ci-hold=unavailable";

function ciHoldUnavailable(step: Phase): boolean {
    return step?.status === "succeeded" && String(step.outputs?.stdout ?? "").split("\n").includes(CI_HOLD_UNAVAILABLE);
}

/** The checkout phase prints the commit it left checked out as one `orbit-commit=<sha>` line. */
const COMMIT = /orbit-commit=([0-9a-f]{40}|[0-9a-f]{64})\b/;

/** The key names phpdotenv reads. */
const KEY = /^[A-Za-z0-9_.]+$/;

/**
 * A clone's checkout part of the workspace phase prints its JSON line with the NAMES of the keys it copied from its default's .env and the keys it
 * dropped (docs/reference/development-containers.md#the-environment-of-a-clone), the names it rewrote to the clone's own
 * host, and the names of the connections it shares with its default. Only names that are environment keys pass.
 */
export function cloneEnvironment(phase: Phase): {copied: string[]; dropped: string[]; rewritten?: string[]; shared?: string[]} | null {
    if (phase?.status !== "succeeded") return null;
    for (const line of String(phase.outputs?.stdout ?? "").split("\n")) {
        let parsed: unknown;
        try { parsed = JSON.parse(line); } catch { continue; }
        const printed = parsed as {phase?: unknown; environment?: unknown} | null;
        if (printed?.phase !== "checkout" || printed.environment == null) continue;
        const names = (raw: unknown) => Array.isArray(raw) ? raw.filter((name): name is string => typeof name === "string" && KEY.test(name)) : [];
        const value = printed.environment as {copied?: unknown; dropped?: unknown; rewritten?: unknown; shared?: unknown};
        // `rewritten`: the copied keys whose value pointed at the default's own site and now points at the clone's.
        // `shared`: the keys of the connections the clone shares with its default, rendered by Orbit as the clone's own.
        return {copied: names(value.copied), dropped: names(value.dropped), ...(Array.isArray(value.rewritten) ? {rewritten: names(value.rewritten)} : {}),
            ...(Array.isArray(value.shared) ? {shared: names(value.shared)} : {})};
    }
    return null;
}

/**
 * A task copy's checkout prints what its cleaning took from the default's tree (docs/reference/development-containers.md#a-task-copy-starts-clean):
 * the counts and the first names of the tracked files it reset and the untracked files and folders it removed. Names only; a clone that
 * is no task copy prints none.
 */
export function cloneCleaned(phase: Phase): {reset_count: number; reset: string[]; removed_count: number; removed: string[]} | null {
    if (phase?.status !== "succeeded") return null;
    for (const line of String(phase.outputs?.stdout ?? "").split("\n")) {
        let parsed: unknown;
        try { parsed = JSON.parse(line); } catch { continue; }
        const printed = parsed as {phase?: unknown; cleaned?: unknown} | null;
        if (printed?.phase !== "checkout" || printed.cleaned == null) continue;
        const value = printed.cleaned as {reset_count?: unknown; reset?: unknown; removed_count?: unknown; removed?: unknown};
        const names = (raw: unknown) => Array.isArray(raw) ? raw.filter((name): name is string => typeof name === "string") : [];
        const count = (raw: unknown) => Number.isSafeInteger(raw) ? raw as number : 0;
        return {reset_count: count(value.reset_count), reset: names(value.reset), removed_count: count(value.removed_count), removed: names(value.removed)};
    }
    return null;
}

/** A part name of a clone phase's `timings`; anything else is dropped. */
const PART = /^[a-z][a-z0-9_]{0,31}$/;

/**
 * The seconds of each part of a clone's phases (docs/reference/development-containers.md#create): the JSON line each phase prints carries
 * `timings`, part names and seconds only. A phase that did not succeed, or printed none, gives none.
 */
export function cloneTimings(phases: Record<string, {phase: string; output: Phase | string | undefined}>): Record<string, Record<string, number>> | null {
    const found: Record<string, Record<string, number>> = {};
    for (const [key, {phase, output}] of Object.entries(phases)) {
        const text = typeof output === "string" ? output : output?.status === "succeeded" ? String(output.outputs?.stdout ?? "") : "";
        for (const line of text.split("\n")) {
            let parsed: unknown;
            try { parsed = JSON.parse(line); } catch { continue; }
            const printed = parsed as {phase?: unknown; timings?: unknown} | null;
            if (printed?.phase !== phase || printed.timings === null || typeof printed.timings !== "object" || Array.isArray(printed.timings)) continue;
            const parts = Object.entries(printed.timings as Record<string, unknown>)
                .filter((entry): entry is [string, number] => PART.test(entry[0]) && typeof entry[1] === "number" && Number.isFinite(entry[1])
                    && entry[1] >= 0 && entry[1] < 100000);
            if (parts.length > 0) found[key] = Object.fromEntries(parts);
        }
    }
    return Object.keys(found).length > 0 ? found : null;
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
    // The mail work runs inside the container step: a step that did not succeed says nothing about mail, so no mail result is reported.
    const mail = (intent.container as {mail?: unknown} | null)?.mail != null && container?.launch === "succeeded"
        ? mailResult(container?.mail) ?? {status: "failed", reason: "instance.mail_update_failed", ports: [1025, 2525]}
        : null;
    const environment = (intent.container as {clone?: unknown} | null)?.clone != null ? cloneEnvironment(container?.workspace) : null;
    const cleaned = (intent.container as {clone?: unknown} | null)?.clone != null ? cloneCleaned(container?.workspace) : null;
    const nested = (intent.container as {club_key?: unknown} | null | undefined)?.club_key != null;
    const club = nested ? clubKeyWord((intent.container as {clone?: unknown}).clone != null, container?.club_key, phases.club_key_file) : null;
    // The container step's own output is the one `mail` names: the clone-container step, whose last line carries its timings.
    const timings = (intent.container as {clone?: unknown} | null)?.clone != null ? cloneTimings({
        container: {phase: "container", output: container?.mail}, checkout: {phase: "checkout", output: container?.workspace},
        t3: {phase: "t3", output: phases.t3_observation},
    }) : null;
    // A clone's Route publication never decides its create: the create ends at the registration, and a Route that is not published
    // gets its completion's publication (docs/reference/development-containers.md#the-address-during-the-create). The Gateway reads
    // a clone create without `route` and with a Route as `deferred`. A merge that a Node refused changed nothing there: the address is
    // deferred to the completion as when the Nodes were held (docs/reference/routes.md#coordinate-publication).
    const publication = (intent.container as {clone?: unknown} | null)?.clone != null && intent.route != null
        ? (phases.route === "succeeded" && certificates.every((status) => status === "succeeded") ? "published"
            : phases.route === "failed" && phases.route_deferred === true ? "deferred" : "failed")
        : null;
    const warnings = [
        ...(mail?.status === "failed" ? [{code: "instance.mail_update_failed", step: "mail"}] : []),
        ...(publication === "failed" ? [{code: "instance.create_route_failed", step: "route"}] : []),
        // A container default runs no Project setup steps; with no development deploy steps either, nothing installed its code.
        ...(intent.setup_steps_ignored === true ? [{code: "instance.setup_steps_ignored", step: "setup"}] : []),
    ];
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        name_converted_from: intent.name_converted_from ?? null,
        ...(commit ? { commit } : {}),
        ...(container !== null && (intent.container as {clone?: unknown} | null)?.clone == null
            ? {node_pin: {status: "not_prepared", reason: "container_from_stock_image"}} : {}),
        ...(server ? { t3: server } : {}),
        ...(environment ? {environment} : {}),
        ...(cleaned ? {workspace_cleaned: cleaned} : {}),
        ...(mail ? {mail} : {}),
        ...(club ? {club_key: club} : {}),
        ...(timings ? {timings} : {}),
        ...(publication ? {route_publication: publication} : {}),
        ...(warnings.length > 0 ? {warnings} : {}),
        ...(failed
            ? {
                failed_step: failed[0],
                // A setup command keeps the public step-list code; T3 that never answers has its own; other phases name themselves.
                // The T3 step's agent part keeps the agent phase's code, so a failure or a timeout there never reads as T3.
                error_code: failed[0] === "ci_hold"
                    ? "instance.ci_hold_unavailable"
                    : failed[0] === "setup"
                    ? "instance.setup_step_failed"
                    : failed[0] === "t3"
                    ? (phases.t3_part === "agent" ? "instance.create_agent_failed" : "instance.t3_unready")
                    : "instance.create_" + failed[0] + "_failed",
                ...(failed[0] === "ci_hold" ? {detail: `The Node ${ciHoldNode(intent)} has no hold script of its ci-runner role. Converge the role on the Node.`} : {}),
            }
            : {}),
    };
    if (phases.t3_observation) {
        // The T3 step prints the checkout's lines before its own JSON line: the first JSON line with the key is read.
        const observed = (raw: string | undefined, key: string): string | null => {
            for (const line of (raw ?? "").split("\n")) {
                try {
                    const value = JSON.parse(line)?.[key];
                    if (typeof value === "string" && Number.isFinite(Date.parse(value))) return new Date(value).toISOString();
                } catch { continue; }
            }
            return null;
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
            sessions_checked_at: observed(phases.t3_observation, "checked_at"),
            route_ready_at: probeTimes.sort().at(-1) ?? null,
        } });
    }
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}

/** The Node of a proof copy's CI place, as the Gateway names it in the hold intent that runs `clone-ci-hold`. */
function ciHoldNode(intent: Record<string, unknown>): string {
    return z.object({ container: z.object({ clone: z.object({ ci_hold: z.object({ node: z.string().min(1) }) }) }) }).parse(intent)
        .container.clone.ci_hold.node;
}

/**
 * A container default's phases in order: the container, the workspace with its commit, each development deploy step, the
 * Processes, the Route and the seed snapshot. A clone's create ends when its thread is usable: the copy, the checkout with its commit,
 * the T3 step (the agent's files, then T3) and the registration, whose receipt must name the server. Its database, a nested fleet and
 * the Processes are its completion's (complete_result.ts); its Route is `route_publication`, which never decides the create.
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
        const unavailable = ciHoldUnavailable(container.ci_hold);
        return [
            ["ci_hold", !unavailable, unavailable],
            ["clone", container.launch === "succeeded", container.launch === "failed"],
            // One step runs the checkout, T3 and the sessions check; the part it was running names the phase that failed.
            ["workspace", commit !== null, container.workspace?.status === "failed" && phases.t3_part === "checkout"],
            ["t3", container.t3 === "succeeded", container.t3 === "failed" && phases.t3_part !== "checkout" && phases.t3_part !== "sessions"],
            [
                "register",
                container.register === "succeeded" && typeof container.server === "string",
                container.register === "failed" || (container.t3 === "failed" && phases.t3_part === "sessions"),
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
        route,
        ["seed", container.seed === "succeeded", container.seed === "failed"],
    ];
}
