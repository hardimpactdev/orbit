import { z } from "npm:zod@4.4.3";
import { checkParts } from "../tasks/check.ts";

const Hash = z.string().regex(/^[0-9a-f]{40}([0-9a-f]{24})?$/);
const Digest = z.string().regex(/^[0-9a-f]{64}$/);
const Identity = z.object({
    root_device: z.number().int(),
    root_inode: z.number().int(),
    git_device: z.number().int(),
    git_inode: z.number().int(),
    branch: z.string(),
});
const Phase = z.enum([
    "preflight",
    "before",
    "after",
    "receipt",
    "export",
    "discard",
    "scan",
    "check",
    "commit",
    "commit-ref",
    "base",
    "base-cleanup",
    "base-merge",
]);
// One guest command or Git read as the task check rules read a step: exit code and, for Git reads, stdout.
const Run = z.object({
    exitCode: z.number().int(),
    stdout: z.string().optional(),
}).strict();
const Snapshot = z.object({
    runtime: z.object({ project: z.string(), name: z.string() }),
    snapshot_id: z.string().uuid(),
    checkout_fingerprint: Identity,
    head: Hash,
    tree: Hash,
    index_tree: Hash,
    index_sha256: Digest,
    digest: Digest,
    before_digest: Digest.optional(),
    changed: z.literal(false),
});
const Evidence = z.union([
    // The handoff check of a copy: a conflict with the default head it brought in names the paths; it comes before the plain
    // refusal, which would drop them.
    z.object({
        error_code: z.literal("tasks.check_conflict"),
        // HEAD and the tree of the work as the implementer handed it off (docs/reference/tasks.md#handoffs).
        handed_head: Hash,
        handed_tree: Hash,
        default_commit: Hash,
        conflicting_paths: z.array(z.string().min(1)).max(100).refine(
            (paths) => new TextEncoder().encode(JSON.stringify(paths)).length <= 3000,
        ),
        conflicting_more: z.number().int().min(0),
        changed: z.literal(false),
    }).strict(),
    // Its merge: nothing when HEAD holds the head, else the merge commit.
    z.object({
        handed_head: Hash,
        handed_tree: Hash,
        default_commit: Hash,
        merged_default: z.null(),
        changed: z.literal(false),
    }).strict(),
    z.object({
        handed_head: Hash,
        handed_tree: Hash,
        default_commit: Hash,
        merged_default: Hash,
        commit: Hash,
        changed: z.literal(true),
    }).strict(),
    // The copy check adds a fixed reason to its refusal; it names no value.
    z.object({
        error_code: z.string().regex(/^tasks\.[a-z_]+$/),
        reason: z.string().regex(/^[A-Za-z0-9 ._-]{1,100}$/).optional(),
        // A check phase that failed after it collected its part table keeps the table.
        parts: z.unknown().optional(),
    }),
    Snapshot,
    z.object({
        checkout_fingerprint: Identity,
        head: Hash,
        branch: z.string(),
        changed: z.literal(false),
    }),
    z.object({
        receipt: z.record(z.string(), z.unknown()),
        bytes: z.number().int().max(65536),
        changed: z.literal(false),
    }),
    z.object({ discarded: z.literal(true), changed: z.literal(false) }),
    z.object({
        head: Hash,
        tree: Hash,
        guest_bundle: z.string(),
        sha256: Digest,
        node_scratch: z.string().startsWith("/var/tmp/orbit-task-export-"),
        bundle: z.string().startsWith("/var/tmp/orbit-task-export-"),
        changed: z.literal(false),
    }),
    // A publication's export: the bundle sits in the publication scratch on the worker, none on the Node.
    z.object({
        head: Hash,
        tree: Hash,
        guest_bundle: z.string(),
        sha256: Digest,
        bundle: z.string().regex(/^\/tmp\/orbit-task-publish-[A-Za-z0-9]+\/candidate\.bundle$/),
        changed: z.literal(false),
    }).strict(),
    z.object({
        check: z.object({
            steps: z.record(
                z.string().regex(/^[a-z_-]+(-[0-9]+)?$/),
                z.object({ outputs: Run }).strict(),
            ),
        }).strict(),
        changed: z.literal(false),
    }).strict(),
    // A clean checked tree makes no commit, so the commit phase may change nothing.
    z.object({ commit_read: Run, changed: z.boolean() }).strict(),
    z.object({
        ref: z.string().regex(/^refs\/orbit\/check-operations\/[1-9][0-9]*$/),
        commit: Hash,
        changed: z.literal(true),
    }).strict(),
    // task:sync of a copy: the default head now in refs/remotes/origin/<default>, and how far the branch is behind it.
    z.object({
        default_branch: z.string().min(1),
        default_commit: Hash,
        branch: z.string().min(1),
        branch_commit: Hash,
        behind_by: z.number().int().min(0),
        start: Hash.nullable(),
        bundled: z.boolean(),
        changed: z.boolean(),
        // The one content read (docs/reference/tasks.md#published-work-of-a-copy): what of the copy is not on the default head.
        work: z.object({
            state: z.enum(["clean", "dirty"]),
            head: Hash,
            default_commit: Hash,
            empty: z.boolean(),
            paths: z.object({
                count: z.number().int().min(0),
                names: z.array(z.string()).max(5),
            }).strict(),
            uncommitted: z.number().int().min(0),
            untracked: z.number().int().min(0),
            stashes: z.number().int().min(0),
            approved_held: z.boolean().nullable(),
        }).strict(),
    }).strict(),
    z.object({ cleaned: z.literal(true), changed: z.literal(false) })
        .strict(),
    // The copy check of a publication: paths, line numbers and secret names, never a value; the publish reads it.
    z.object({
        scan: z.object({
            secrets: z.array(z.object({
                path: z.string(),
                line: z.number().int().min(1).optional(),
                secret: z.string().min(1),
            }).strict()).max(20),
            secrets_more: z.number().int().min(0),
            sources: z.array(z.string()),
            values: z.number().int().min(0),
            short_skipped: z.record(z.string(), z.number().int().min(0)),
            objects: z.number().int().min(0),
            objects_sha256: Digest,
            seconds: z.number().min(0),
        }).strict(),
        changed: z.literal(false),
    }).strict(),
]);
const Result = z.object({ phase: Phase, evidence: Evidence });
const Args = z.object({
    folder: z.string().regex(/^\/tmp\/orbit-ansible-[0-9a-f-]{36}$/),
    phase: Phase,
});
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

// Core command/shell cannot turn the private JSON file into typed child evidence.
// All machine work stays in Ansible; this method only bounds and validates its result.
/**
 * The evidence with each part table redacted before it is first stored (docs/reference/tasks.md#the-delivery-gate): a check's `parts`
 * step and a failed check's `parts`. A table that is not one, also after redaction, is dropped.
 */
function redactedParts(evidence: z.infer<typeof Evidence>): z.infer<typeof Evidence> {
    if ("parts" in evidence && evidence.parts !== undefined) {
        const { parts, ...rest } = evidence;
        const table = checkParts(parts);
        return table === null ? rest : { ...rest, parts: table };
    }
    if (!("check" in evidence) || evidence.check.steps.parts === undefined) return evidence;
    const { parts, ...steps } = evidence.check.steps;
    let table = null;
    try {
        table = checkParts(JSON.parse(parts.outputs.stdout ?? ""));
    } catch {
        table = null;
    }
    return {
        ...evidence,
        check: { steps: table === null ? steps : { ...steps, parts: { outputs: { ...parts.outputs, stdout: JSON.stringify(table) } } } },
    };
}

export const behavior = {
    type: "@nckrtl/task-instance-workspace",
    version: "2026.11.16.1",
    resources: {
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        result: {
            description:
                "Read bounded, typed evidence from one workspace phase",
            arguments: Args,
            async execute(args: z.infer<typeof Args>, ctx: Context) {
                const file = await Deno.open(args.folder + "/result.json", {
                    read: true,
                });
                const bytes = new Uint8Array(524289);
                let length = 0;
                try {
                    while (length < bytes.length) {
                        const count = await file.read(bytes.subarray(length));
                        if (count === null) break;
                        length += count;
                    }
                } finally {
                    file.close();
                }
                if (length > 524288) {
                    throw new Error("tasks.workspace_phase_failed");
                }
                const evidence = Evidence.parse(
                    JSON.parse(
                        new TextDecoder("utf-8", { fatal: true }).decode(
                            bytes.subarray(0, length),
                        ),
                    ),
                );
                const result = Result.parse({ phase: args.phase, evidence: redactedParts(evidence) });
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
