import { z } from "npm:zod@4.4.3";
export const SqlitePath = z.string().startsWith("/").refine((v) =>
    v.length > 1 && !v.endsWith("/") && !/[\0\r\n]/.test(v) &&
    !/(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(v)
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Pin = z.string().regex(/^[a-f0-9]{64}$/);
const Placement = z.object({
    node_id: z.number().int().positive(),
    transport_user: User,
});
export const CloneSource = Placement.extend({
    variant: z.literal("clone"),
    checkout: SqlitePath,
    source: SqlitePath,
    directory: z.string().regex(/^\/tmp\/orbit-sqlite-clone-[a-f0-9]{32}$/),
}).strict().refine((v) => v.source.startsWith(v.checkout + "/"));
export const SeedSource = Placement.extend({
    variant: z.literal("seed"),
    target_instance_id: z.number().int().positive(),
    base: SqlitePath,
    source_path: SqlitePath,
    source_user: User,
    operation: Pin,
    state_dir: SqlitePath,
    snapshot_path: SqlitePath,
}).strict().refine((v) =>
    v.source_path.startsWith(v.base + "/") &&
    v.snapshot_path === `/tmp/orbit-sqlite-${v.operation}.sqlite` &&
    new RegExp(
        `^/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-${v.target_instance_id}(?:-${v.operation})?$`,
    ).test(v.state_dir)
);
export const SnapshotSource = z.union([CloneSource, SeedSource]);
export const SeedTarget = Placement.extend({
    target_instance_id: z.number().int().positive(),
    base: SqlitePath,
    target_user: User,
    operation: Pin,
    state_dir: SqlitePath,
    environment: z.enum(["development", "production"]),
    relative_path: z.string().min(1).refine((v) =>
        !v.startsWith("/") && !/[\0\r\n]/.test(v) &&
        !/(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(v)
    ),
}).strict().refine((v) =>
    new RegExp(
        `^/var/lib/orbit/app-instance-sqlite-seeds/target-${v.target_instance_id}(?:-${v.operation})?$`,
    ).test(v.state_dir) &&
    (v.environment !== "production" ||
        (v.base === `/home/${v.target_user}` &&
            v.relative_path === "database.sqlite"))
);
export const SnapshotRef = z.object({
    model_id: z.string().uuid(),
    name: z.string().regex(/^snapshot-[a-f0-9-]{36}$/),
    version: z.number().int().positive(),
    bytes: z.number().int().min(16).max(2 ** 40),
    sha256: Pin,
    mode: z.number().int().min(0).max(0o7777).optional(),
}).strict();
