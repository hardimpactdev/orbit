import { z } from "npm:zod@4.4.3";

const Name = z.string().regex(/^[A-Za-z0-9_]{1,64}$/);
const system = (name: string) =>
    ["mysql", "sys", "performance_schema", "information_schema", "postgres"]
        .includes(name.toLowerCase()) ||
    name.toLowerCase().startsWith("template");
export const CreateInput = z.object({
    repair: z.boolean(),
    driver: z.enum(["mysql", "pgsql", "redis"]),
    username: z.string().regex(/^[A-Za-z0-9_-]{1,63}$/),
    databases: z.array(Name).max(34),
    test_prefix: Name.nullable(),
    locale: z.enum(["C", "C.UTF-8"]).nullable(),
    host: z.ipv4(),
    port: z.number().int().min(1).max(65535),
    container: z.string().regex(
        /^orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*$/,
    ).nullable(),
    index: z.number().int().nonnegative().nullable(),
    patterns: z.array(
        z.string().min(1).max(1024).regex(/^\S+$/).refine((s) =>
            !s.includes("\0")
        ),
    ).max(100),
    allow: z.array(z.string().regex(/^@?[a-z][a-z0-9|_-]*$/)).max(100),
}).strict().refine((i) => {
    if (i.driver === "redis") {
        return i.databases.length === 0 && i.container === null &&
            i.test_prefix === null;
    }
    if (
        !i.container || !i.databases.length ||
        i.databases.some(system) ||
        new Set(i.databases).size !== i.databases.length
    ) return false;
    if (
        !/^[A-Za-z0-9_]+$/.test(i.username) ||
        (i.driver === "mysql" && i.username.length > 32)
    ) return false;
    if (
        i.driver === "pgsql" &&
        (i.databases.some((n) => n.length > 63) || i.test_prefix !== null)
    ) return false;
    return i.test_prefix === null ||
        ![
            "mysql",
            "sys",
            "performance_schema",
            "information_schema",
            "postgres",
            "template",
        ].some((n) =>
            n.startsWith(i.test_prefix!.toLowerCase()) ||
            i.test_prefix!.toLowerCase().startsWith("template")
        );
});
const Codes = z.enum([
    "database.server_admin_invalid",
    "database.server_admin_failed",
    "database.server_not_ready",
    "database.name_conflict",
    "database.source_missing",
    "database.server_command_failed",
    "database.index_invalid",
]);
export const CreateResult = z.union([
    z.object({ backend: z.literal("active") }).strict(),
    z.object({ error_code: Codes }).strict(),
]);
