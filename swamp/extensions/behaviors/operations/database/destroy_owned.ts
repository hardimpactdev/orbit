import { z } from "npm:zod@4.4.3";
import { invokeSql } from "./local_sql.ts";
import { runSqliteLocal, type SqliteInvoke } from "./sqlite_local.ts";
import { destroyDatabase, DestroyInput } from "./destroy.ts";
import type { PrivateMaterialUse } from "./private_material.ts";

const Path = z.string().startsWith("/").refine((p) =>
    !/[\0\r\n]|(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(p)
);
const Server = z.object({
    server_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    intent: DestroyInput,
}).strict();
const Sqlite = z.object({
    connection_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    checkout: Path,
    path: Path,
}).strict()
    .refine((v) => v.path.startsWith(v.checkout + "/"));
const Work = z.discriminatedUnion("type", [
    z.object({ type: z.literal("server"), key: z.string(), server: Server }),
    z.object({ type: z.literal("sqlite"), key: z.string(), sqlite: Sqlite }),
]);
const Material = z.object({
    payload: z.object({
        passwords: z.record(
            z.string().regex(/^[1-9][0-9]*$/),
            z.string().max(16384),
        ),
    }).strict(),
}).strict();
const SqliteResult = z.union([
    z.object({ cancelled: z.literal(true) }).strict(),
    z.object({ backend: z.literal("absent") }).strict(),
    z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);

/** Compose one placed removal into any native parent DAG, without a child Operation. */
export async function destroyOwnedStep(
    raw: unknown,
    use: PrivateMaterialUse,
    signal?: AbortSignal,
    invoke = invokeSql,
    destroy = destroyDatabase,
    sqliteInvoke: SqliteInvoke = runSqliteLocal,
) {
    try {
        const work = Work.parse(raw);
        signal?.throwIfAborted();
        if (work.type === "server") {
            return await destroy(
                work.server.intent,
                async (consume) => {
                    await use(async (rawMaterial) => {
                        const material = Material.parse(rawMaterial);
                        const password = material.payload
                            .passwords[String(work.server.server_id)];
                        if (password === undefined) {
                            throw new Error("Missing admitted password");
                        }
                        await consume({ password });
                    });
                },
                signal,
                invoke,
            );
        }
        const result = await sqliteInvoke({
            action: "copy",
            input: {
                operation: "remove",
                checkout: work.sqlite.checkout,
                path: work.sqlite.path,
            },
        }, signal);
        if (result && typeof result === "object" && "error_code" in result) {
            return SqliteResult.parse(result);
        }
        z.object({}).passthrough().parse(result);
        return { backend: "absent" as const };
    } catch {
        throw new Error("database.destroy_owned_outcome_unknown");
    }
}
