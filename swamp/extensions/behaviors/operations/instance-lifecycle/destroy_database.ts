import { z } from "npm:zod@4.4.3";
import { destroyOwnedStep } from "../database/destroy_owned.ts";
import { DestroyInput } from "../database/destroy.ts";
import type { GatewayOperation } from "../_lib/direct.ts";

const Id = z.number().int().positive();
const Path = z.string().startsWith("/").refine((path) =>
  !/[\0\r\n]|(?:^|\/)\.{1,2}(?:\/|$)|\/\//.test(path)
);
const Server = z.object({ server_id: Id, node_id: Id, intent: DestroyInput })
  .strict();
const Sqlite = z.object({
  connection_id: Id,
  node_id: Id,
  checkout: Path,
  path: Path,
}).strict().refine((row) => row.path.startsWith(row.checkout + "/"));

const Selection = z.object({
  owner_instance_id: Id,
  intent_id: Id,
  servers: z.array(Server).max(1000),
  sqlite: z.array(Sqlite).max(10000),
});
function uniqueWork(input: z.infer<typeof Selection>, ctx: z.RefinementCtx) {
  if (
    new Set(input.servers.map((row) => row.server_id)).size !==
      input.servers.length ||
    new Set(input.sqlite.map((row) => row.connection_id)).size !==
      input.sqlite.length
  ) {
    ctx.addIssue({ code: "custom", message: "Duplicate removal work" });
  }
}
/** Participant views contain only their placed descriptors, not the complete row-deletion set. */
export const DestroyDatabaseSelection = Selection.superRefine(uniqueWork);
/** One DB-owned aggregate intent covers the entire admitted removal set. */
export const DestroyDatabase = Selection.extend({
  connection_ids: z.array(Id).max(10000),
}).strict().superRefine((input, ctx) => {
  uniqueWork(input, ctx);
  if (
    new Set(input.connection_ids).size !== input.connection_ids.length ||
    input.sqlite.some((row) =>
      !input.connection_ids.includes(row.connection_id)
    )
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Duplicate or foreign removal work",
    });
  }
});
export const DestroyDatabaseWork = z.discriminatedUnion("type", [
  z.object({
    type: z.literal("server"),
    key: z.string(),
    node_id: Id,
    server: Server,
  }).strict(),
  z.object({
    type: z.literal("sqlite"),
    key: z.string(),
    node_id: Id,
    sqlite: Sqlite,
  }).strict(),
]);

export function destroyDatabaseWork(raw: unknown) {
  const input = DestroyDatabaseSelection.parse(raw);
  return [
    ...input.servers.map((server) => ({
      type: "server" as const,
      key: `server-${server.server_id}`,
      node_id: server.node_id,
      server,
    })),
    ...input.sqlite.map((sqlite) => ({
      type: "sqlite" as const,
      key: `sqlite-${sqlite.connection_id}`,
      node_id: sqlite.node_id,
      sqlite,
    })),
  ];
}

/** The placed participant receives only its own declared DB material. */
export async function removeDestroyDatabase(
  rawWork: unknown,
  nodeId: number,
  admittedDatabase: unknown,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  signal?: AbortSignal,
  remove = destroyOwnedStep,
) {
  const work = DestroyDatabaseWork.parse(rawWork);
  const admitted = destroyDatabaseWork(admittedDatabase).find((row) =>
    row.key === work.key
  );
  if (
    work.node_id !== nodeId || !admitted ||
    JSON.stringify(admitted) !== JSON.stringify(work)
  ) throw new Error("database.removal_conflict");
  signal?.throwIfAborted();
  const result = await remove(
    work,
    (consume) =>
      gateway.withPrivateMaterial((material) =>
        consume({ payload: material.database_material })
      ),
    signal,
  );
  if ("error_code" in result) throw new Error(result.error_code);
  return result;
}
