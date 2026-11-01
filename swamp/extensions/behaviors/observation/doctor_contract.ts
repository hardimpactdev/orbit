import { z } from "npm:zod@4.4.3";

export const Families = [
    "node",
    "role",
    "project",
    "instance",
    "schedule",
    "tool",
    "process",
    "firewall",
    "database_connection",
    "route",
    "database_server",
] as const;
export type Family = typeof Families[number];
export type Row = Record<string, unknown>;
const Id = z.union([z.number().int().positive(), z.string()]);
export const Ssh = z.object({
    host: z.string(),
    user: z.string(),
    port: z.number().int().positive(),
    host_keys: z.array(z.string()).min(1),
});
export const NodeInput = z.object({
    id: z.number().int().positive(),
    name: z.string(),
    platform: z.string(),
    architecture: z.string(),
    user: z.string(),
    managed: z.boolean(),
    parked: z.boolean(),
    ssh: Ssh.optional(),
    roles: z.array(z.object({
        id: z.number().int().positive(),
        role: z.string(),
        status: z.string(),
        services: z.array(z.string()),
    })).default([]),
    resources: z.object({
        node_checks: z.array(z.object({ status: z.string() })).default([]),
        processes: z.array(z.object({
            id: z.number().int().positive(),
            name: z.string(),
            runtime: z.enum(["systemd", "docker"]),
            desired: z.enum(["running", "stopped"]),
            keep_alive: z.boolean(),
            hibernation_path: z.string().nullable(),
            input: z.object({ name: z.string() }),
        })).default([]),
    }),
    observations: z.object({
        freshness: z.string(),
        valid_until: z.number().nullable(),
    }).passthrough().optional(),
});
export type NodeIntent = z.infer<typeof NodeInput>;
export const Input = z.object({
    nodes: z.array(NodeInput),
    families: z.array(z.enum(Families)),
    collect_problems: z.boolean(),
    signals: z.unknown().optional(),
    logs: z.object({
        log_directory: z.string(),
        base_directory: z.string(),
        cursor: z.object({
            path: z.string(),
            inode: z.number().int(),
            offset: z.number().int().nonnegative(),
        }).nullable(),
    }).optional(),
});
export const Read = z.object({
    key: z.string(),
    node_id: z.number().int().positive(),
    ssh: Ssh,
    family: z.enum(["node", "role", "process"]),
    resource: z.object({ id: Id, name: z.string() }),
    check: z.enum([
        "platform",
        "disk",
        "agent",
        "service",
        "systemd",
        "docker",
        "awake",
    ]),
    argv: z.array(z.string()),
    sudo: z.boolean(),
});
export type Read = z.infer<typeof Read>;
export const Issue = z.object({
    code: z.string(),
    kind: z.enum(["drift", "unverifiable", "informational"]),
    resource_type: z.enum(Families),
    resource_id: Id.nullable(),
    resource_name: z.string().nullable(),
    summary: z.string(),
    expected: z.union([z.boolean(), z.string()]).nullable(),
    observed: z.union([z.boolean(), z.string()]).nullable(),
});
export type Issue = z.infer<typeof Issue>;
export const FamilyReport = z.object({
    family: z.enum(Families),
    status: z.enum(["healthy", "drift", "unverifiable"]),
    checked: z.number().int().nonnegative(),
    issues: z.array(Issue),
    observed_resources: z.array(Id),
    observed_checks: z.record(z.string(), z.array(z.string())),
    observed_inventories: z.record(z.string(), z.array(Id)),
});
export type FamilyReport = z.infer<typeof FamilyReport>;
export function issue(
    family: Family,
    code: string,
    resource: { id: number | string; name: string },
    summary: string,
    expected: string | null = "matching",
    observed: string | null = "mismatch",
    kind: Issue["kind"] = "drift",
): Issue {
    return {
        code,
        kind,
        resource_type: family,
        resource_id: resource.id,
        resource_name: resource.name.slice(0, 255),
        summary: summary.slice(0, 1000),
        expected: expected?.slice(0, 255) ?? null,
        observed: observed?.slice(0, 255) ?? null,
    };
}
export function report(
    family: Family,
    checked: number,
    issues: Issue[],
    observed_checks: Record<string, string[]> = {},
): FamilyReport {
    return {
        family,
        checked,
        issues,
        observed_resources: [],
        observed_checks,
        observed_inventories: {},
        status: issues.some((i) => i.kind === "unverifiable")
            ? "unverifiable"
            : issues.some((i) => i.kind === "drift")
            ? "drift"
            : "healthy",
    };
}
