import { z } from "npm:zod@4.4.3";
import { Step } from "./result.ts";

/** The statuses of the steps of a move (docs/cli/instance/migrate.mmd). Temporary, with the command. */
export const MigratePhases = z.object({
    mail: z.string().optional(),
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
const MailReceipt = z.object({
    mail: z.object({status: z.enum(["applied", "unchanged", "skipped"]),
        ports: z.tuple([z.literal(1025), z.literal(2525)]),
        reason: z.enum(["mail_port_in_use", "mail_unit_conflict"]).optional()}),
    mailer: z.object({status: z.enum(["changed", "left_as_written", "not_present", "already_smtp"]),
        lines: z.array(z.number().int().positive()).optional()}).optional(),
}).refine((receipt) => {
    if (receipt.mail.status === "skipped") return receipt.mail.reason !== undefined && receipt.mailer === undefined;
    if (receipt.mail.reason !== undefined || receipt.mailer === undefined) return false;
    if (receipt.mailer.status === "changed") return receipt.mailer.lines?.length === 1;
    if (receipt.mailer.status === "left_as_written") return receipt.mailer.lines !== undefined;
    return receipt.mailer.lines === undefined;
});
type WrittenFailure = Partial<z.infer<typeof MailReceipt>> & {
    error_code?: string;
    error_message?: string;
    preflight_refused?: boolean;
    retained_git_path?: string;
    retained_git_at?: string;
};
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
    const processes = (intent.migrate as { processes?: { translations?: { process_id: number; process_name: string; from: string; to: string }[] }[] } | undefined)?.processes ?? [];
    const translations = processes.flatMap((process) => process.translations ?? []);
    const routed = intent.route_mutations != null;
    const commit = workspace?.status === "succeeded" ? COMMIT.exec(String(workspace.outputs?.stdout ?? ""))?.[1] ?? null : null;
    const done = (name: Exclude<keyof Phases, "mail">): [string, boolean, boolean] => [name, phases[name] === "succeeded", ["failed", "cancelled", "interrupted", "unknown"].includes(phases[name])];
    // [phase, finished, failed], in the order the run takes them.
    const outcomes: [string, boolean, boolean][] = [
        done("container"),
        done("quiesce"),
        ["workspace", commit !== null, ["failed", "cancelled", "interrupted", "unknown"].includes(phases.workspace)],
        done("files"),
        ...(clone === null ? [] : [done("agent")]),
        done("processes"),
        ...(clone === null ? [done("seed")] : [
            done("t3"),
            ["register", phases.register === "succeeded" && typeof server === "string", ["failed", "cancelled", "interrupted", "unknown"].includes(phases.register)] as [string, boolean, boolean],
        ]),
        ...(routed ? [done("route")] : []),
        done("retire"),
    ];
    const failed = outcomes.find(([, , failure]) => failure) ?? outcomes.find(([, finished]) => !finished);
    const moved = ["succeeded", "failed", "cancelled", "interrupted"].includes(phases.retire);
    const refusal = failed !== undefined && WRITES_RESULT.includes(failed[0]) && written !== null && CODE.test(written.error_code ?? "") &&
            !written.error_code?.startsWith("instance.container_")
        ? written.error_code ?? null
        : null;
    const parsedMail = phases.mail === "succeeded" ? MailReceipt.safeParse(written) : null;
    const mail = parsedMail?.success ? parsedMail.data : null;
    const mailWarning = phases.mail !== undefined && phases.mail !== "skipped" && mail === null;
    const result = {
        instance_id: intent.instance_id,
        ready: failed === undefined,
        moved,
        restored: !moved && phases.restore === "succeeded" && ["succeeded", "skipped"].includes(phases.restore_route),
        ...(mail ?? {}),
        ...(mailWarning
            ? {warnings: [{code: "instance.mail_update_failed", step: "mail"}]} : {}),
        ...(translations.length ? { process_translations: translations } : {}),
        ...(failed?.[0] === "container" && refusal !== null && written?.preflight_refused === true ? { preflight_refused: true } : {}),
        ...(commit ? { commit } : {}),
        ...(moved && written?.retained_git_path && written?.retained_git_at ? { retained_git_path: written.retained_git_path, retained_git_at: written.retained_git_at } : {}),
        ...(moved && server ? { t3: JSON.parse(server) } : {}),
        ...(refusal !== null && written?.error_message ? { error_message: written.error_message } : {}),
        ...(failed ? { failed_step: failed[0], error_code: refusal ?? "instance.migrate_" + failed[0] + "_failed" } : {}),
    };
    return { ready: failed === undefined, stdout: JSON.stringify(result) };
}

/** Read the public phase failure and the separate retention receipt, which survives a later retirement failure. */
export async function writtenFailure(folder: string): Promise<WrittenFailure | null> {
    let failure: WrittenFailure = {};
    let retained: WrittenFailure = {};
    try {
        const value = JSON.parse(await Deno.readTextFile(`${folder}/result.json`));
        const parsed = z.object({ error_code: z.string().regex(CODE), error_message: z.string().optional(), preflight_refused: z.boolean().optional() }).safeParse(value);
        if (parsed.success) failure = parsed.data;
    } catch { /* A phase can fail before it writes a result. */ }
    try {
        const value = JSON.parse(await Deno.readTextFile(`${folder}/retained-git.json`));
        const parsed = z.object({ retained_git_path: z.string().min(1), retained_git_at: z.iso.datetime({ offset: true }) }).safeParse(value);
        if (parsed.success) retained = parsed.data;
    } catch { /* No checkout was retained. */ }
    let mail: Partial<z.infer<typeof MailReceipt>> = {};
    try {
        const parsed = MailReceipt.safeParse(JSON.parse(await Deno.readTextFile(`${folder}/mail-result.json`)));
        if (parsed.success) mail = parsed.data;
    } catch { /* A bounded mail failure is named by its phase status, without failing migration. */ }
    const result = { ...failure, ...retained, ...mail };
    return Object.keys(result).length > 0 ? result : null;
}
