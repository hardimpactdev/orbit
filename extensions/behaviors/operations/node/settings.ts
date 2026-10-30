import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { Globals } from "../../operation-runner/mod.ts";

export const Storage = z.object({
  root: z.string().regex(/^\/(?:[^/\0\r\n]+\/)*[^/\0\r\n]+$/).refine(
    (path) =>
      path.split("/").slice(1).every((part) => part !== "." && part !== ".."),
  ),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  group: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  create_missing: z.boolean(),
}).strict();
export const Prepare = z.object({
  agent_pin: z.string().regex(/^[a-f0-9]{64}$/),
  storage: Storage,
}).strict();
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** One fixed local primitive; no SSH, receipt, installation or retry protocol. */
export async function prepareStorage(
  args: z.infer<typeof Prepare>,
  signal?: AbortSignal,
) {
  signal?.throwIfAborted();
  const child = new Deno.Command("/usr/bin/sudo", {
    args: [
      "-n",
      "/usr/local/bin/orbit-agent",
      "command",
      "node.prepare-storage-root",
    ],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: signal
      ? AbortSignal.any([signal, AbortSignal.timeout(35000)])
      : AbortSignal.timeout(35000),
  }).spawn();
  const writer = child.stdin.getWriter();
  try {
    await writer.write(new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: args.agent_pin,
        deadline_ms: 30000,
        input: args.storage,
      }) + "\n",
    ));
    await writer.close();
    let size = 0;
    const chunks: Uint8Array[] = [];
    for await (const chunk of child.stdout) {
      size += chunk.length;
      if (size > 16384) {
        throw new Error("Storage action output exceeded its bound");
      }
      chunks.push(chunk);
    }
    const status = await child.status;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    let envelope: unknown;
    try {
      envelope = JSON.parse(new TextDecoder().decode(bytes));
    } catch {
      throw new Error("Storage action returned no typed result");
    }
    const success = z.object({
      ok: z.literal(true),
      data: z.object({}).strict(),
    }).safeParse(envelope);
    if (status.success && success.success) return true;
    const refusal = z.object({
      ok: z.literal(false),
      error: z.object({ code: z.literal("node.storage_root_unusable") })
        .passthrough(),
    }).safeParse(envelope);
    if (refusal.success) return false;
    throw new Error("Storage action outcome is uncertain");
  } finally {
    try {
      child.kill("SIGTERM");
    } catch { /* Already exited. */ }
    await child.status;
  }
}

const Args = DirectArgs.extend({
  kind: z.literal("node.settings"),
  node_id: z.literal(209),
});
const Result = z.union([
  z.object({ prepared: z.literal(true) }).strict(),
  z.object({ error_code: z.literal("node.settings_root_failed") }).strict(),
]);

export function settings(
  action = prepareStorage,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/node-settings",
    version: "2026.10.30.4",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Prepare one admitted apps root locally and project the bounded result",
        arguments: Args,
        execute: (args: z.infer<typeof Args>, ctx: Context) =>
          directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            async (input) =>
              await action(Prepare.parse(input), ctx.signal)
                ? { prepared: true }
                : { error_code: "node.settings_root_failed" },
            Result.parse,
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = { ...settings(), type: "@nckrtl/node-settings" };
