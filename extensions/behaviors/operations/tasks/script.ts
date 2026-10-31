import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { withTaskCancellation } from "./cancellation.ts";
import { ScriptProgram } from "./script_program.ts";

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
export type ScriptExecutor = (
  input: Record<string, unknown>,
  signal?: AbortSignal,
) => Promise<unknown>;

/** The native step owns the process; abort requests stop its systemd cgroup before returning. */
export async function workspaceProgram(
  program: string,
  input: Record<string, unknown>,
  signal?: AbortSignal,
): Promise<unknown> {
  signal?.throwIfAborted();
  const process = new Deno.Command("/usr/bin/python3", {
    args: ["-c", program],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const stop = () => {
    try {
      process.kill("SIGTERM");
    } catch { /* Already ended. */ }
  };
  signal?.addEventListener("abort", stop, { once: true });
  try {
    const writer = process.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(input)));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of process.stdout) {
      size += chunk.length;
      if (size > 1048576) {
        throw new Error("Native script output exceeded its bound");
      }
      chunks.push(chunk);
    }
    if (!(await process.status).success) {
      throw new Error("Native script outcome unknown");
    }
    const data = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      data.set(chunk, offset);
      offset += chunk.length;
    }
    return JSON.parse(new TextDecoder().decode(data));
  } finally {
    signal?.removeEventListener("abort", stop);
    stop();
    await process.status;
  }
}

export const localScript: ScriptExecutor = (input, signal) =>
  workspaceProgram(ScriptProgram, input, signal);

export async function script(
  input: z.infer<typeof ScriptInput>,
  operationId: number,
  invoke = localScript,
  signal?: AbortSignal,
) {
  const result = ScriptResult.parse(
    await invoke(
      { ...input, execution_key: `operation-${operationId}` },
      signal,
    ),
  );
  if ("cancelled" in result && result.cancelled) {
    throw new DirectOperationCancelled({ effectRunning: false });
  }
  return result;
}
const Args = DirectArgs.extend({
  node_id: Id,
  kind: z.literal("tasks.script"),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export const scriptBehavior = {
  type: "@nckrtl/task-script",
  version: "2026.10.31.1",
  globalArguments: Globals,
  methods: {
    execute: {
      description: "Run the admitted tracked repository script in its task VM",
      arguments: Args,
      execute: (args: z.infer<typeof Args>, ctx: Context) => {
        const gateway = new GatewayOperation(
          gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
          args,
        );
        return directOperation(
          args,
          gateway,
          (raw) => {
            const input = ScriptInput.parse(raw);
            if (input.node_id !== args.node_id) {
              throw new Error(
                "Wrong task VM Node",
              );
            }
            return withTaskCancellation(
              gateway,
              (signal) => script(input, args.operation_id, localScript, signal),
              ctx.signal,
            );
          },
          (result) => ScriptResult.parse(result),
          ctx.signal,
        );
      },
    },
  },
};
