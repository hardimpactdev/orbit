import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { scheduleCall } from "./contract.ts";

const Kind = z.enum(["schedule.enable", "schedule.run", "schedule.destroy"]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Common = z.object({
  id: z.string().uuid(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
const ControlInput = Common.extend({
  user: z.string().regex(/^[a-z_][a-z0-9_-]*$/),
  working_directory: z.string().startsWith("/").max(4096),
}).strict();
const RemoveInput = Common.extend({
  group: z.string().regex(/^[a-z_][a-z0-9_-]*$/),
  cascade: z.boolean(),
}).strict();
const Input = z.union([ControlInput, RemoveInput]);
const Result = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Verify the admitted build before allowing the Node user's sudo action. */
export function assertAgent(
  file: Pick<Deno.FileInfo, "isFile" | "isSymlink" | "uid" | "mode">,
  digest: string,
  admitted: string,
) {
  if (
    !file.isFile || file.isSymlink || file.uid !== 0 || file.mode === null ||
    (file.mode & 0o022) !== 0 || digest !== admitted
  ) {
    throw new Error(
      "The local agent does not match its admitted root-owned build",
    );
  }
}

/** Fixed Node action; no Gateway relay, keyed effect receipt, or retry loop. */
export async function localControl(
  kind: z.infer<typeof Kind>,
  input: z.infer<typeof Input>,
  signal?: AbortSignal,
) {
  const call = scheduleCall(kind, input);
  const binary = "/usr/local/bin/orbit-agent";
  const file = await Deno.lstat(binary);
  const digest = await crypto.subtle.digest(
    "SHA-256",
    await Deno.readFile(binary),
  );
  const pin = Array.from(
    new Uint8Array(digest),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  assertAgent(file, pin, input.agent_sha256);
  const deadline = kind === "schedule.run"
    ? 15000
    : kind === "schedule.enable"
    ? 45000
    : 60000;
  const bounded = AbortSignal.timeout(deadline);
  const process = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", binary, "command", call.name],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: signal ? AbortSignal.any([signal, bounded]) : bounded,
  }).spawn();
  try {
    const writer = process.stdin.getWriter();
    await writer.write(new TextEncoder().encode(
      JSON.stringify({
        agent_sha256: input.agent_sha256,
        deadline_ms: deadline,
        input: call.input,
      }) + "\n",
    ));
    await writer.close();
    const chunks: Uint8Array[] = [];
    let size = 0;
    for await (const chunk of process.stdout) {
      size += chunk.length;
      if (size > 16384) {
        throw new Error("Schedule Node action output is too large");
      }
      chunks.push(chunk);
    }
    const status = await process.status;
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) {
      bytes.set(chunk, offset);
      offset += chunk.length;
    }
    const reply = z.discriminatedUnion("ok", [
      z.object({ ok: z.literal(true), data: z.unknown() }),
      z.object({
        ok: z.literal(false),
        error: z.object({ code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }),
      }),
    ]).parse(JSON.parse(new TextDecoder().decode(bytes)));
    if (!reply.ok) return { error_code: reply.error.code };
    if (!status.success) throw new Error("Schedule Node action failed");
    return { ready: true };
  } catch {
    // Never include stdout, stderr, input or parser diagnostics in native history.
    throw new Error("Schedule Node action did not return a bounded result");
  } finally {
    try {
      process.kill("SIGKILL");
    } catch { /* already exited */ }
    await process.status;
  }
}

export function scheduleControl(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action = localControl,
) {
  return {
    type: "@nckrtl/schedule-control",
    version: "2026.10.30.3",
    globalArguments: Globals,
    methods: {
      control: {
        description: "Apply admitted Schedule control on its exact Node worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            (input) =>
              action(
                args.kind,
                args.kind === "schedule.destroy"
                  ? RemoveInput.parse(input)
                  : ControlInput.parse(input),
                ctx.signal,
              ),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = scheduleControl();
