import { z } from "npm:zod@4.4.3";
import { Step } from "./result.ts";

/** The statuses of the steps of a move (docs/cli/instance/migrate.mmd). Temporary, with the command. */
export const MigratePhases = z.object({
    quiesce: z.string(),
    container: z.string(),
    workspace: z.string(),
    files: z.string(),
    agent: z.string(),
    processes: z.string(),
    seed: z.string(),
    t3: z.string(),
    register: z.string(),
    route: z.string(),
    retire: z.string(),
    restore_route: z.string(),
    restore: z.string(),
});
type WrittenFailure = { error_code: string; error_message?: string; preflight_refused?: boolean };
type Phases = z.infer<typeof MigratePhases>;

/** The workspace phase prints the commit it left checked out as one `orbit-commit=<sha>` line. */
const COMMIT = /orbit-commit=([0-9a-f]{40}|[0-9a-f]{64})\b/;
/** A public error code, as a phase's refusal writes it into the run folder. */
const CODE = /^[a-z]+\.[a-z0-9_]+$/;
/** The phases that write `result.json`: the Ansible ones. The Route child and the registration step write none. */
const WRITES_RESULT = ["quiesce", "container", "workspace", "files", "agent", "processes", "seed", "t3", "retire"];

/**
 * Map the step statuses of a move to its terminal receipt. `moved` says that the Route serves the container and the checkout's
 * removal began: from there the container is the Instance, whatever the removal did. `restored` says that a move that failed
 * before that removed its container and put the Route back, so the reserved runtime can go. A failed phase's own refusal, read
 * from the run folder, is the error code; a phase that merely failed names itself.
 */
export function migrateResult(
    intent: Record<string, unknown>,
    phases: Phases,
    workspace: z.infer<typeof Step> | null,
    server: string | null,
    written: WrittenFailure | null,
) {
    const clone = (intent.container as { clone?: unknown } | null)?.clone ?? null;
    const routed = intent.route_mutations != null;
    const commit = workspace?.status === "succeeded" ? COMMIT.exec(String(workspace.outputs?.stdout ?? ""))?.[1] ?? null : null;
    const done = (name: keyof Phases): [string, boolean, boolean] => [name, phases[name] === "succeeded", phases[name] === "failed"];
    // [phase, finished, failed], in the order the run takes them.
    const outcomes: [string, boolean, boolean][] = [
        done("quiesce"),
        done("container"),
        ["workspace", commit !== null, phases.workspace === "failed"],
        done("files"),
        ...(clone === null ? [] : [done("agent")]),
        done("processes"),
        ...(clone === null ? [done("seed")] : [
            done("t3"),
            ["register", phases.register === "succeeded" && typeof server === "string", phases.register === "failed"] as [string, boolean, boolean],
        ]),
        ...(routed ? [done("route")] : []),
        done("retire"),
    ];
    const failed = outcomes.find(([, , failure]) => failure) ?? outcomes.find(([, finished]) => !finished);
    const moved = phases.retire !== "skipped";
    const refusal = failed !== undefined && WRITES_RESULT.includes(failed[0]) && written !== null && CODE.test(written.error_code) &&
            !written.error_code.startsWith("instance.container_")
        ? written.error_code
        : null;
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        moved,
        restored: !moved && phases.restore === "succeeded" && phases.restore_route !== "failed",
        ...(failed?.[0] === "quiesce" && refusal === "instance.migrate_retirement_blocked" && written?.preflight_refused === true ? { preflight_refused: true } : {}),
        ...(commit ? { commit } : {}),
        ...(moved && server ? { t3: JSON.parse(server) } : {}),
        ...(refusal === "instance.migrate_retirement_blocked" && written?.error_message ? { error_message: written.error_message } : {}),
        ...(failed ? { failed_step: failed[0], error_code: refusal ?? "instance.migrate_" + failed[0] + "_failed" } : {}),
    };
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}

/** The public refusal that the last failed phase wrote, or null when it wrote none. */
export async function writtenFailure(folder: string): Promise<WrittenFailure | null> {
    try {
        const value = JSON.parse(await Deno.readTextFile(`${folder}/result.json`));
        const parsed = z.object({ error_code: z.string().regex(CODE), error_message: z.string().optional(), preflight_refused: z.boolean().optional() }).safeParse(value);
        return parsed.success ? parsed.data : null;
    } catch {
        return null;
    }
}
