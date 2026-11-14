import { z } from "npm:zod@4.4.3";

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
    "check",
    "commit",
    "commit-ref",
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
    z.object({ error_code: z.string().regex(/^tasks\.[a-z_]+$/) }),
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
export const behavior = {
    type: "@nckrtl/task-instance-workspace",
    version: "2026.11.14.2",
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
                const result = Result.parse({ phase: args.phase, evidence });
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
