import { processSystem } from "../processes-schedules/process-system.ts";
import { invokeSql } from "./local_sql.ts";
import { writeValkeyAcl } from "./valkey_acl_local.ts";

/** Closed database actions use the shared Process implementation and fixed SQL/ACL actions. */
export type ServerRuntime = {
  converge(
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>>;
  remove(
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>>;
  restart(
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>>;
  writeAcl(
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>>;
  ready(
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>>;
};
export function serverRuntime(
  process = processSystem(),
  sql = invokeSql,
  acl = writeValkeyAcl,
): ServerRuntime {
  return {
    converge: (input, signal) =>
      process("process.converge-container", input, signal),
    remove: (input, signal) =>
      process("process.remove", {
        process_id: input.process_id,
        runtime: "docker",
        name: input.container,
        vite: null,
      }, signal),
    restart: (input, signal) =>
      process("process.control", {
        process_id: input.process_id,
        runtime: "docker",
        name: input.container,
        operation: "restart",
        on_demand: false,
      }, signal),
    writeAcl: (input, signal) => acl(input, signal),
    ready: (input, signal) =>
      sql({
        name: "database.server-admin",
        input,
        mutates: false,
        deadlineMs: 150_000,
        maxOutputBytes: 1024 * 1024,
      }, signal),
  };
}
export const localServerRuntime = serverRuntime();
