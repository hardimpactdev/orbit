import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const ScriptInput = z.object({
    group_id: Id,
    instance_id: Id,
    node_id: Id,
    subtask: Id,
    checkout_path: z.string().startsWith("/"),
    script: z.string().max(512).regex(/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_.-]+)*$/),
    args: z.array(z.string().max(4096).refine((value) => !value.includes("\0")))
        .max(32),
    ssr_port: z.number().int().min(1).max(65535).nullable(),
});
export const ScriptResult = z.union([
    z.object({ error_code: z.string().regex(/^[a-z0-9_.]+$/) }).strict(),
    z.object({
        outcome: z.enum(["passed", "failed"]),
        exit_code: z.number().int().nullable(),
        output: z.string().max(16384),
        output_truncated: z.boolean(),
        changed: z.boolean(),
        head: Sha,
        tree: Sha,
        cancelled: z.literal(true).optional(),
    }).strict(),
]);

export const AnalysisInput = z.object({
    group_id: Id,
    instance_id: Id,
    node_id: Id,
    subtask: Id,
    checkout_path: z.string().startsWith("/"),
    ssr_port: z.number().int().min(1).max(65535).nullable(),
    plan: z.object({ id: z.string(), revision: Id }).passthrough(),
    plan_sha256: z.string().regex(/^[a-f0-9]{64}$/),
    base: Sha,
    head: Sha,
    tree: Sha,
    repository: z.string().min(1),
    branch: z.string().min(1),
});
const Failure = z.object({ error_code: z.string().regex(/^[a-z0-9_.]+$/) })
    .passthrough();
export const NativeResult = z.union([
    Failure,
    z.object({ cancelled: z.literal(true) }).strict(),
    z.object({
        outcome: z.enum(["ready", "action_required", "blocked", "changed"]),
        artifact: z.object({
            sha256: z.string().regex(/^[a-f0-9]{64}$/),
            bytes: z.number().int().min(1).max(65536),
            content: z.string(),
        }).strict(),
        provenance: z.record(z.string(), z.unknown()),
    }).strict(),
]);
