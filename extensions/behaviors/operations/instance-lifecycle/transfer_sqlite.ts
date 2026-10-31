import { z } from "npm:zod@4.4.3";
import {
  cleanupSnapshot,
  exportSnapshot,
  type FileContext,
  receiveSnapshot,
  SeedSource,
  SeedTarget,
  seedTarget,
  type Snapshot,
} from "../database/sqlite_actions.ts";
import { invokeLocalEnvironment } from "../instance-environment/local.ts";

export const TransferSqlite = z.object({
  owner_instance_id: z.number().int().positive(),
  source_instance_id: z.number().int().positive(),
  participant_node_ids: z.array(z.number().int().positive()),
  source: SeedSource,
  target: SeedTarget,
}).strict().refine((v) =>
  v.owner_instance_id === v.source_instance_id &&
  v.owner_instance_id === v.source.target_instance_id &&
  v.owner_instance_id === v.target.target_instance_id &&
  v.source.node_id !== v.target.node_id &&
  v.source.operation === v.target.operation &&
  v.target.environment === "development" &&
  v.source.source_path === v.source.base + "/" + v.target.relative_path &&
  v.participant_node_ids.length === 2 &&
  v.participant_node_ids.includes(v.source.node_id) &&
  v.participant_node_ids.includes(v.target.node_id)
);
export type TransferSqlite = z.infer<typeof TransferSqlite>;

/** Compose the canonical DB and environment actions; only native artifacts carry bytes. */
export function transferSqliteActions(
  exportSource = exportSnapshot,
  target = seedTarget,
  receive = receiveSnapshot,
  cleanSource = cleanupSnapshot,
  environment = invokeLocalEnvironment,
) {
  return {
    export: (
      plan: TransferSqlite,
      ctx: FileContext,
      runId: string,
      signal?: AbortSignal,
    ) => exportSource(plan.source, ctx, `snapshot-${runId}`, undefined, signal),
    install: async (
      plan: TransferSqlite,
      snapshot: Snapshot,
      ctx: FileContext,
      refresh: () => Promise<unknown>,
      signal?: AbortSignal,
    ) => {
      await refresh();
      const prepared = await target(
        plan.target,
        "prepare",
        snapshot,
        undefined,
        signal,
      );
      await refresh();
      if (prepared.state === "ready") {
        await receive(
          {
            transport_user: plan.target.transport_user,
            base: plan.target.base,
            path: prepared.incoming,
          },
          snapshot,
          ctx,
        );
        await refresh();
      } else if (!["resume", "complete"].includes(prepared.state)) {
        throw new Error("database.sqlite_target_failed");
      }
      if (prepared.state !== "complete") {
        const installed = await target(
          plan.target,
          "install",
          snapshot,
          undefined,
          signal,
        );
        if (!["changed", "complete"].includes(installed.state)) {
          throw new Error("database.sqlite_target_failed");
        }
      }
    },
    environment: async (
      plan: TransferSqlite,
      path: string,
      signal?: AbortSignal,
    ) => {
      if (!path.startsWith(plan.target.base + "/") || !path.endsWith("/.env")) {
        throw new Error("instance.transfer_intent_invalid");
      }
      // Single-quoted dotenv values do not interpolate $. Refuse quote/control bytes.
      const database = plan.target.base + "/" + plan.target.relative_path;
      if (
        database.includes("'") || database.includes("\\") ||
        [...database].some((value) =>
          value.charCodeAt(0) < 32 || value.charCodeAt(0) === 127
        )
      ) {
        throw new Error("env.configuration_invalid");
      }
      const result = await environment({
        name: "instance.write-environment",
        input: {
          base: path.slice(0, -5),
          user: plan.target.target_user,
          maximum: 1048576,
          required_capacity: 1048576,
          require_home: false,
          mode: "merge",
          file: ".env",
          managed_keys: ["DB_CONNECTION", "DB_DATABASE"],
          content: `DB_CONNECTION=sqlite\nDB_DATABASE='${database}'\n`,
        },
      }, signal);
      if (!["changed", "unchanged"].includes(String(result.state))) {
        throw new Error("env.sync_unconfirmed");
      }
    },
    cleanupSource: (plan: TransferSqlite) => cleanSource(plan.source),
    cleanupTarget: async (plan: TransferSqlite) => {
      if (
        (await target(plan.target, "cleanup", undefined)).state !== "cleaned"
      ) {
        throw new Error("database.sqlite_cleanup_failed");
      }
    },
  };
}
