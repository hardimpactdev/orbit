import { z } from "npm:zod@4.4.3";

/*
 * Typed data for tasks.publish and the handoff push of tasks.check. The task
 * VM work is one official `@swamp/ssh exec` argv per step and the 209 work is
 * inline `git` and `gh api` in `command/shell`; this module only builds their
 * inputs and reads their outputs. It runs nothing and holds no token.
 */

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const PublishInput = z.object({
    group_id: Id,
    instance_id: Id,
    node_id: Id,
    check: Id.nullable(),
    checkout_path: z.string().startsWith("/"),
    commit: Sha,
    tree: Sha,
    repository: z.string().regex(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
    base: z.string().min(1),
    branch: z.string().regex(/^task-[1-9][0-9]*$/),
    pull_request: z.object({
        title: z.string().min(1).max(256),
        body: z.string().max(65536),
        draft: z.boolean().optional(),
    }).nullable(),
});
type Input = z.infer<typeof PublishInput>;
type Steps = Record<string, unknown>;

const ObjectValue = z.record(z.string(), z.unknown());
const Run = z.object({
    exitCode: z.number().int().nullable().optional(),
    stdout: z.string().optional(),
    stderr: z.string().optional(),
}).passthrough();
export type Run = z.infer<typeof Run>;

/** Outputs of one step, or an empty record when it was skipped. */
export function run(steps: Steps, name: string): Run {
    const step = ObjectValue.safeParse(steps[name]);
    if (!step.success) return {};
    const outputs = ObjectValue.safeParse(step.data.outputs);
    return Run.parse(outputs.success ? outputs.data : {});
}

const ran = (item: Run) => typeof item.exitCode === "number";
const json = (text: string | undefined): unknown => {
    try {
        return JSON.parse(text ?? "");
    } catch {
        return undefined;
    }
};

/** The remote and refspec of a push without force; Git refuses a divergent tip by itself. */
export function pushTarget(repository: string, commit: string, branch: string) {
    return {
        remote: `https://github.com/${repository}.git`,
        refspec: `${commit}:refs/heads/${branch}`,
        object_format: commit.length === 64 ? "sha256" : "sha1",
    };
}

/** The bundled HEAD and its tree, read back from the bare repository on 209. */
export function imported(item: Run, commit: string, tree: string) {
    if (!ran(item)) return { imported: false, error_code: "" };
    if (item.exitCode !== 0) {
        return {
            imported: false,
            error_code: "tasks.repository_execution_unknown",
        };
    }
    const [head, bundled] = (item.stdout ?? "").trim().split("\n");
    return head === commit && bundled === tree
        ? { imported: true, error_code: "" }
        : { imported: false, error_code: "tasks.head_moved" };
}

/** One `git push` without force: success, or its public refusal code. */
export function pushed(item: Run) {
    if (!ran(item)) return { pushed: false, error_code: "" };
    if (item.exitCode === 0) return { pushed: true, error_code: "" };
    const text = (item.stdout ?? "") + (item.stderr ?? "");
    const error_code = text.includes("refusing to allow a GitHub App") &&
            text.includes(".github/workflows/")
        ? "tasks.workflows_permission"
        : text.includes("non-fast-forward") || text.includes("(fetch first)")
        ? "tasks.not_fast_forward"
        : "tasks.push_failed";
    return { pushed: false, error_code };
}

/** HEAD and the whole working tree must still equal the checked candidate. */
export function verify(raw: unknown) {
    const { input, steps } = z.object({
        input: PublishInput,
        steps: ObjectValue,
    }).parse(raw);
    const oid = (name: string) => {
        const item = run(steps, name);
        const value = (item.stdout ?? "").trim();
        return item.exitCode === 0 && Sha.safeParse(value).success
            ? value
            : null;
    };
    const head = oid("snapshot-head"), tree = oid("snapshot-tree");
    if (head === null || tree === null) {
        return {
            admitted: false,
            error_code: "tasks.repository_execution_unknown",
        };
    }
    return head === input.commit && tree === input.tree
        ? { admitted: true, error_code: "" }
        : { admitted: false, error_code: "tasks.head_moved" };
}

const PullRow = z.object({
    number: Id,
    state: z.string(),
    base: z.object({ ref: z.string() }).passthrough(),
    head: z.object({
        ref: z.string(),
        repo: z.object({ full_name: z.string() }).passthrough().nullable(),
    }).passthrough(),
}).passthrough();

/** One open PR lookup decides one update or one creation, with caller prose unchanged. */
export function pullPlan(raw: unknown) {
    const { input, steps } = z.object({
        input: PublishInput,
        steps: ObjectValue,
    }).parse(raw);
    const none = { method: "", path: "", body: "", number: 0 };
    const item = run(steps, "read_pull");
    if (input.pull_request === null || !ran(item)) {
        return { ...none, error_code: "" };
    }
    const rows = z.array(PullRow).safeParse(json(item.stdout));
    if (item.exitCode !== 0 || !rows.success) {
        return { ...none, error_code: "tasks.publish_failed" };
    }
    const matches = rows.data.filter((row) =>
        row.head.ref === input.branch &&
        row.head.repo?.full_name.toLowerCase() ===
            input.repository.toLowerCase()
    );
    if (matches.length > 1) {
        return { ...none, error_code: "tasks.publish_failed" };
    }
    const path = `repos/${input.repository}/pulls`;
    if (matches.length === 0) {
        return {
            method: "POST",
            path,
            body: JSON.stringify({
                ...input.pull_request,
                head: input.branch,
                base: input.base,
            }),
            number: 0,
            error_code: "",
        };
    }
    const row = matches[0];
    if (
        row.state !== "open" || row.base.ref !== input.base
    ) return { ...none, error_code: "tasks.head_moved" };
    return {
        method: "PATCH",
        path: `${path}/${row.number}`,
        body: JSON.stringify({
            title: input.pull_request.title,
            body: input.pull_request.body,
        }),
        number: row.number,
        error_code: "",
    };
}
