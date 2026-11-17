import { z } from "npm:zod@4.4.3";

/*
 * Typed data for tasks.publish. Instance phases export the copy; inline `git`
 * and `gh api` in `command/shell` publish it from the worker. This module only
 * builds their inputs and reads their outputs. It runs nothing and holds no token.
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

const PullRow = z.object({
    number: Id,
    state: z.string(),
    base: z.object({ ref: z.string() }).passthrough(),
    head: z.object({
        ref: z.string(),
        repo: z.object({ full_name: z.string() }).passthrough().nullable(),
    }).passthrough(),
}).passthrough();

/** The most paths a base statement or a conflict names; the count beyond it is stated. */
export const BASE_PATHS = 100;
/**
 * The most bytes a path list takes, with its prefix and its count: well under the 4,096 bytes of a failed step's detail that the
 * Gateway keeps (NativeStepFailure), so a conflict line arrives whole, and small beside a pull request body's 65,536 characters.
 */
export const BASE_BYTES = 3000;
const bytes = (text: string) => new TextEncoder().encode(text).length;

/** The first paths whose quoted list fits `budget` bytes, at most BASE_PATHS of them, and how many are left out. */
function bounded(paths: string[], budget: number) {
    const shown: string[] = [];
    let used = 2;
    for (const path of paths) {
        const size = bytes(JSON.stringify(path)) + (shown.length === 0 ? 0 : 2);
        if (shown.length === BASE_PATHS || used + size > budget) break;
        shown.push(path);
        used += size;
    }
    return { shown, more: paths.length - shown.length };
}

/** One refusal line: its code, then the quoted items that fit BASE_BYTES, then how many more there were. */
function refusal(prefix: string, items: string[], extra: number) {
    const total = items.length + extra;
    const { shown, more } = bounded(items, BASE_BYTES - bytes(prefix) - bytes(` and ${total} more`));
    return prefix + shown.map((item) => JSON.stringify(item)).join(", ") + (more + extra > 0 ? ` and ${more + extra} more` : "");
}
export const BaseFacts = z.object({
    base_commit: Sha,
    default_commit: Sha,
    behind_by: z.number().int().min(0),
    overlapping_paths: z.array(z.string().min(1)).max(BASE_PATHS).refine(
        (paths) => bytes(JSON.stringify(paths)) <= BASE_BYTES,
    ),
    overlapping_more: z.number().int().min(0),
    // The default head the worker merged at publish, or null when the push sends the candidate itself.
    merged_default: Sha.nullable(),
}).strict();
export type BaseFacts = z.infer<typeof BaseFacts>;

/** NUL-separated Git names, unique, in Git's order. */
const names = (
    text: string,
) => [...new Set(text.split("\0").filter((name) => name !== ""))];

/**
 * The files "Read the base" wrote: the default head, the merge base, how far behind, the overlapping paths, the copy's own workflow
 * paths, the merge-tree reply and the merge at publish. A path under .github/workflows/ that the candidate changed since the merge
 * base refuses first, because the token has no workflows permission; then exit 0 states the base and passes the merge at publish,
 * or "" when the push sends the candidate; exit 3 is a real conflict, refused with its paths; anything else is an error of the read.
 * A workflow list or merge file the read did not write is an error, never an empty one.
 */
export function baseFacts(
    exitCode: number,
    files: Record<"head" | "base" | "behind" | "overlap" | "merge", string> & { workflows: string | null; merged: string | null },
) {
    const head = files.head.trim(),
        base = files.base.trim(),
        behind = files.behind.trim();
    const read = Sha.safeParse(head).success && Sha.safeParse(base).success &&
        /^[0-9]+$/.test(behind) && files.workflows !== null;
    const none = { facts: null, merged: "" };
    if (read && (exitCode === 0 || exitCode === 3)) {
        const workflows = names(files.workflows ?? "");
        if (workflows.length > 0) {
            return { ...none, error_code: refusal("tasks.publish_workflow_change: ", workflows, 0) };
        }
    }
    if (exitCode === 3 && read) {
        // merge-tree --name-only -z: the tree, then each conflicted path.
        const conflicts = names(files.merge).slice(1);
        if (conflicts.length > 0) {
            return { ...none, error_code: refusal("tasks.publish_conflict: ", conflicts, 0) };
        }
    }
    const merged = (files.merged ?? "").trim();
    if (exitCode !== 0 || !read || files.merged === null || (merged !== "" && !Sha.safeParse(merged).success)) {
        return { ...none, error_code: "tasks.publish_failed" };
    }
    const overlap = bounded(names(files.overlap), BASE_BYTES);
    return {
        facts: BaseFacts.parse({
            base_commit: base,
            default_commit: head,
            behind_by: Number(behind),
            overlapping_paths: overlap.shown,
            overlapping_more: overlap.more,
            merged_default: merged === "" ? null : head,
        }),
        merged,
        error_code: "",
    };
}

/** Marks Orbit's own section, so a body that carries an earlier one gets it replaced, never twice. */
export const BASE_MARK = "<!-- orbit:base -->";

/**
 * The body without an earlier Orbit section: its marker, heading and list lines go, and any text after it stays, such as the waiver
 * lines a copy's publish appends to the caller's body (withWaivers), which may follow a section the caller's body carried.
 */
function withoutBase(body: string): string {
    const lines = body.split("\n");
    const at = lines.findIndex((line) => line.replace(/\r$/, "") === BASE_MARK);
    if (at === -1) return body.trimEnd();
    let end = at + 1;
    if (lines[end]?.replace(/\r$/, "") === "## Base") end++;
    if (lines[end]?.replace(/\r$/, "") === "") end++;
    while (end < lines.length && /^(?:- |  - )/.test(lines[end])) end++;
    return [
        lines.slice(0, at).join("\n").trimEnd(),
        lines.slice(end).join("\n").trim(),
    ]
        .filter((part) => part !== "").join("\n\n");
}

/**
 * The body, then the fixed `## Base` section of a task copy's publish; without facts the body is left as it is. The body is the one
 * the copy's .env content check read: the caller's text and the waiver lines (withWaivers). A merge at publish names the default
 * branch and its head. The whole stays within BodyLimit: a section whose path list does not fit names only their count, and one
 * that does not fit at all is left out; the publish result states the base either way.
 */
export function withBase(body: string, facts: BaseFacts | null, branch: string): string {
    if (facts === null) return body;
    const kept = withoutBase(body);
    const code = (text: string) => "`" + text.replaceAll("`", "'") + "`";
    const count = facts.overlapping_paths.length + facts.overlapping_more;
    const listed = facts.overlapping_paths.length === 0
        ? ["- Overlapping paths: none"]
        : [
            "- Overlapping paths:",
            ...facts.overlapping_paths.map((path) => `  - ${code(path)}`),
            ...(facts.overlapping_more > 0
                ? [`  - and ${facts.overlapping_more} more`]
                : []),
        ];
    const counted = [
        `- Overlapping paths: ${count}, listed in the publish result`,
    ];
    const section = (paths: string[]) =>
        (kept === "" ? "" : kept + "\n\n") + [
            BASE_MARK,
            "## Base",
            "",
            `- Checked base: ${code(facts.base_commit)}`,
            `- Default branch head: ${code(facts.default_commit)}`,
            `- Behind by: ${facts.behind_by} ${
                facts.behind_by === 1 ? "commit" : "commits"
            }`,
            ...(facts.merged_default === null ? [] : [`- Merged ${branch} ${code(facts.merged_default)} at publish`]),
            ...paths,
        ].join("\n") + "\n";
    for (const paths of count === 0 ? [listed] : [listed, counted]) {
        const result = section(paths);
        if (result.length <= BodyLimit) return result;
    }
    return body;
}

/** One open PR lookup decides one update or one creation, with caller prose unchanged but for the base section. */
export function pullPlan(raw: unknown) {
    const { input, steps, base } = z.object({
        input: PublishInput,
        steps: ObjectValue,
        // The JSON statement of "Check the base", or "" before that step.
        base: z.string().optional(),
    }).parse(raw);
    const facts = base ? BaseFacts.parse(JSON.parse(base)) : null;
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
                body: withBase(input.pull_request.body, facts, input.base),
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
            body: withBase(input.pull_request.body, facts, input.base),
        }),
        number: row.number,
        error_code: "",
    };
}

/** The limit of a pull request body that the publish request accepts (docs/cli/task/publish.mdx). */
export const BodyLimit = 65536;
// One waiver line, in the form the docs-impact gate reads. The rule's owner is DocsImpact::WAIVER_LINE in
// apps/docs/app/Documentation/DocsImpact.php; this copy must match it, and apps/docs/tests/Unit/WaiverLinePatternTest.php checks that.
const WaiverLine = /^Docs-unaffected: docs\/[^\s:*?]+\.mdx?:[ \t]*\S[^\r\n]*$/;

/**
 * The body a copy publishes: the caller's body, then each waiver line of the copy's proposed body that the body does not hold yet,
 * in the file's order, as long as the body stays within the limit. The proposed body is data: only its waiver lines are read, and
 * the .env content check reads the result before any token exists.
 */
export function withWaivers(body: string, proposed: string | null): string {
    if (proposed === null) return body;
    const held = new Set(
        body.split("\n").map((line) => line.replace(/\r$/, "")),
    );
    const lines: string[] = [];
    for (const raw of proposed.split("\n")) {
        const line = raw.replace(/\r$/, "");
        if (WaiverLine.test(line) && !held.has(line)) {
            held.add(line);
            lines.push(line);
        }
    }
    let result = body;
    let separator = body === "" ? "" : body.endsWith("\n") ? "\n" : "\n\n";
    for (const line of lines) {
        const next = result + separator + line;
        if (next.length > BodyLimit) break;
        result = next;
        separator = "\n";
    }

    return result;
}

const CopyHit = z.object({
    path: z.string(),
    line: z.number().int().min(1).optional(),
    secret: z.string().min(1),
}).strict();
const CopyScan = z.object({
    secrets: z.array(CopyHit),
    secrets_more: z.number().int().min(0),
    sources: z.array(z.string()),
    values: z.number().int().min(0),
    short_skipped: z.record(z.string(), z.number().int().min(0)),
    objects: z.number().int().min(0),
    objects_sha256: z.string().regex(/^[0-9a-f]{64}$/),
    seconds: z.number().min(0),
}).strict();

/**
 * The copy check of a publication (docs/reference/tasks.md#the-copy-check), as its workspace phase recorded it. A secret of the copy in
 * the range refuses with its paths, lines and secret names; an error of the check refuses as unchecked. Workflow changes are judged by
 * baseFacts, against the real default head. A pass keeps the public facts: what was read, what was skipped, and the digest of the objects the check read.
 */
export function copyCheck(raw: unknown) {
    const evidence = z.object({ phase: z.literal("scan"), evidence: z.unknown() }).parse(raw).evidence;
    const parsed = z.object({ scan: CopyScan, changed: z.literal(false) }).strict().safeParse(evidence);
    if (!parsed.success) {
        // The program's own refusal keeps its fixed reason, such as "the nested copy shows no inner machine".
        const refused = z.object({ error_code: z.literal("tasks.publish_secret_unchecked"), reason: z.string().regex(/^[A-Za-z0-9 ._-]{1,100}$/) })
            .safeParse(evidence);
        return { checked: false, error_code: "tasks.publish_secret_unchecked" + (refused.success ? ": " + refused.data.reason : "") };
    }
    const scan = parsed.data.scan;
    if (scan.secrets.length > 0) {
        return {
            checked: false,
            error_code: refusal(
                "tasks.publish_secret_value: ",
                scan.secrets.map((hit) => `${hit.path}${hit.line === undefined ? "" : ":" + hit.line} ${hit.secret}`),
                scan.secrets_more,
            ),
        };
    }
    const { secrets: _s, secrets_more: _m, ...facts } = scan;
    return { checked: true, error_code: "", ...facts };
}
