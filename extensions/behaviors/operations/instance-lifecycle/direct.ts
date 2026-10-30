import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { type Discard, discard, discardDestination } from "./direct_discard.ts";

const Args = DirectArgs.extend({
  kind: z.literal("instance.rename"),
  node_id: z.number().int().positive(),
});
const LifecycleArgs = Args.extend({
  kind: z.enum(["instance.rename", "instance.transfer_discard"]),
});
const Source = z.object({
  repository: z.string().min(1),
  checkout: z.string().startsWith("/"),
  root: z.string().startsWith("/"),
  user: z.string().min(1),
  group: z.string().min(1),
  prepare_id: z.string().nullable(),
  seed_repository: z.string().nullable(),
  verify_origin: z.literal(false),
  require_idle: z.literal(true),
}).strict();
const Input = z.object({
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  repository_identity: z.string().min(1),
  branch: z.string().nullable(),
  source: Source,
}).strict();
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Result = z.union([
  z.object({ source_verified: z.literal(true), branch: z.string().nullable() })
    .strict(),
  z.object({ error_code: Code }).strict(),
  z.object({ discarded: z.literal(true) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Inspect = (input: z.infer<typeof Input>) => Promise<unknown>;

/** One fixed read-only account command. No shell, sudo, install or retry. */
export async function inspectSource(input: z.infer<typeof Input>) {
  const child = new Deno.Command("/usr/local/bin/orbit-agent", {
    args: ["command", "instance.inspect-development-source"],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: input.agent_sha256,
        deadline_ms: 120_000,
        input: input.source,
      }) + "\n",
    ));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let length = 0;
    for await (const chunk of child.stdout) {
      length += chunk.length;
      if (length > 16_384) throw new Error("Invalid agent response");
      chunks.push(chunk);
    }
    if (!(await child.status).success) throw new Error("Agent command failed");
    const bytes = new Uint8Array(length);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    return JSON.parse(new TextDecoder().decode(bytes));
  } finally {
    // A malformed/oversized local response must not leave an orphaned reader.
    try {
      child.kill("SIGTERM");
    } catch { /* already exited */ }
    await child.status;
  }
}

/** Same accepted Git origin forms as admission, without returning raw URLs. */
function repositoryIdentity(origin: string): string | null {
  if (!origin || /[\p{Z}\p{C}]/u.test(origin) || /[?#]/.test(origin)) {
    return null;
  }
  let host: string;
  let path: string;
  const scp = /^git@([^:\s?#]+):([^\s?#]+)$/u.exec(origin);
  if (scp) {
    [, host, path] = scp;
  } else {
    let url: URL;
    try {
      url = new URL(origin);
    } catch {
      return null;
    }
    if (
      !["https:", "ssh:"].includes(url.protocol) || url.password ||
      (url.protocol === "https:" && url.username) || !url.hostname ||
      !url.pathname
    ) return null;
    host = url.hostname;
    path = /^\w+:\/\/[^/]+(\/.*)$/.exec(origin)?.[1] ?? "";
  }
  path = path.replace(/^\/+|\/+$/g, "").replace(/\.git$/, "").replace(
    /\/+$/,
    "",
  );
  return `${host.toLowerCase()}/${path}`;
}

async function rename(input: Record<string, unknown>, inspect: Inspect) {
  const admitted = Input.parse(input);
  const output = z.union([
    z.object({
      ok: z.literal(true),
      data: z.object({
        origin: z.string(),
        branch: z.string().nullable(),
        commit: z.string().nullable(),
      }),
    }),
    z.object({ ok: z.literal(false), error: z.object({ code: Code }) }),
  ]).parse(await inspect(admitted));
  if (!output.ok) return { error_code: output.error.code };
  if (repositoryIdentity(output.data.origin) !== admitted.repository_identity) {
    return { error_code: "instance.source_identity_invalid" };
  }
  if (admitted.branch !== null && output.data.branch !== admitted.branch) {
    return { error_code: "instance.branch_not_checked_out" };
  }
  return { source_verified: true as const, branch: output.data.branch };
}

export function lifecycleDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  inspect: Inspect = inspectSource,
  remove: Discard = discardDestination,
) {
  return {
    type: "@nckrtl/instance-lifecycle-direct",
    version: "2026.10.30.3",
    globalArguments: Globals,
    methods: {
      execute: {
        description: "Execute the admitted lifecycle action on its exact Node",
        arguments: LifecycleArgs,
        execute: async (args: z.infer<typeof LifecycleArgs>, ctx: Context) => {
          LifecycleArgs.parse(args);
          return await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (input) =>
              args.kind === "instance.rename"
                ? rename(input, inspect)
                : discard(input, remove),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
      bindingCanary: {
        description:
          "Prove the bound Gateway read and projection without a Node action",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          Args.parse(args);
          return await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            () =>
              Promise.resolve({
                error_code: "instance.node_action_unavailable",
              }),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
      rename: {
        description: "Record a branch already checked out on the admitted Node",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          Args.parse(args);
          return await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (input) => rename(input, inspect),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}

export const behavior = {
  ...lifecycleDirect(),
  type: "@nckrtl/instance-lifecycle-direct",
};
