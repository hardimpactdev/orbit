import { z } from "npm:zod@4.4.3";
import { CreateInput } from "./create_input.ts";
const Id = z.number().int().positive();
const Path = z.string().startsWith("/").refine((p) =>
    p.length > 1 && !p.endsWith("/") &&
    !/[\0\r\n]|(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(p)
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Ssh = z.object({
    host: z.ipv4(),
    user: User,
    host_keys: z.array(z.string()).min(1),
}).strict();
export const MysqlCloneInput = CreateInput.safeExtend({
    driver: z.literal("mysql"),
    source_database: z.string().regex(/^[A-Za-z0-9_]{1,64}$/).refine((n) =>
        ![
            "mysql",
            "sys",
            "performance_schema",
            "information_schema",
            "postgres",
        ].includes(n.toLowerCase()) && !n.toLowerCase().startsWith("template")
    ),
}).refine((i) =>
    !i.databases.includes(i.source_database) &&
    (i.test_prefix === null || !i.source_database.startsWith(i.test_prefix))
);
const common = {
    node_id: Id,
    source_node_id: Id,
    source_ssh: Ssh,
    target_ssh: Ssh,
};
export const CloneInput = z.discriminatedUnion("variant", [
    z.object({ ...common, variant: z.literal("mysql"), mysql: MysqlCloneInput })
        .strict().refine((i) =>
            i.node_id === i.source_node_id &&
            i.source_ssh.host === i.target_ssh.host
        ),
    z.object({
        ...common,
        variant: z.enum(["sqlite_local", "sqlite_remote"]),
        source: z.object({
            variant: z.literal("clone"),
            node_id: Id,
            transport_user: User,
            checkout: Path,
            source: Path,
            directory: z.string().regex(
                /^\/tmp\/orbit-sqlite-clone-[a-f0-9]{32}$/,
            ),
        }).strict(),
        target: z.object({
            node_id: Id,
            transport_user: User,
            checkout: Path,
            target: Path,
            incoming: z.string().regex(/\/\.orbit-sqlite-[a-f0-9]{32}$/),
        }).strict(),
    }).strict().refine((i) =>
        i.source.node_id === i.source_node_id &&
        i.target.node_id === i.node_id &&
        i.source.transport_user === i.source_ssh.user &&
        i.target.transport_user === i.target_ssh.user &&
        i.source.source.startsWith(i.source.checkout + "/") &&
        i.target.target.startsWith(i.target.checkout + "/") &&
        i.target.incoming.slice(0, i.target.incoming.lastIndexOf("/")) ===
            i.target.target.slice(0, i.target.target.lastIndexOf("/")) &&
        i.source.source.slice(i.source.checkout.length) ===
            i.target.target.slice(i.target.checkout.length) &&
        (i.variant === "sqlite_local") === (i.node_id === i.source_node_id) &&
        (i.source_node_id !== i.node_id || i.source.source !== i.target.target)
    ),
]);
