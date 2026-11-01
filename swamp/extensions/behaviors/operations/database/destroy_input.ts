import { z } from "npm:zod@4.4.3";

export const Name = z.string().regex(/^[A-Za-z0-9_]{1,64}$/);
const User = z.string().min(1).max(128).refine((v) => !/[\s\0]/.test(v));
export const DestroyInput = z.object({
    driver: z.enum(["mysql", "pgsql", "redis"]),
    container: z.string().regex(
        /^orbit-process-[1-9][0-9]*-[a-z0-9]+(?:-[a-z0-9]+)*$/,
    ).nullable(),
    host: z.ipv4(),
    port: z.number().int().min(1).max(65535),
    default_open: z.boolean(),
    databases: z.array(Name).max(10000),
    users: z.array(User).max(10000),
    test_prefixes: z.array(Name).max(1000),
    owners: z.record(Name, Name),
}).strict().refine((v) => v.driver === "redis" || v.container !== null);
