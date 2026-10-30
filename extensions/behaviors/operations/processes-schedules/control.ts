import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { scheduleCall } from "./contract.ts";

const Kind = z.enum(["schedule.enable", "schedule.run"]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Input = z.object({
  id: z.string().uuid(),
  user: z.string().min(1),
  working_directory: z.string().min(1),
}).strict();
const Result = z.union([
  z.object({ ready: z.literal(true) }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };

/** Fixed Node action; no Gateway relay, keyed effect receipt, or retry loop. */
export async function localControl(
  kind: z.infer<typeof Kind>,
  input: z.infer<typeof Input>,
) {
  const call = scheduleCall(kind, input);
  const binary = "/usr/local/bin/orbit-agent";
  const digest = await crypto.subtle.digest(
    "SHA-256",
    await Deno.readFile(binary),
  );
  const pin = Array.from(
    new Uint8Array(digest),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
  const process = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", "--", binary, "command", call.name],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const writer = process.stdin.getWriter();
  await writer.write(new TextEncoder().encode(
    JSON.stringify({
      agent_sha256: pin,
      deadline_ms: 60000,
      input: call.input,
    }) + "\n",
  ));
  await writer.close();
  const output = await process.output();
  if (!output.success) {
    throw new Error("Schedule Node action did not return a result");
  }
  const reply = z.discriminatedUnion("ok", [
    z.object({ ok: z.literal(true), data: z.unknown() }),
    z.object({
      ok: z.literal(false),
      error: z.object({ code: z.string().regex(/^[a-z0-9_.-]+$/) }),
    }),
  ]).parse(JSON.parse(new TextDecoder().decode(output.stdout)));
  return reply.ok ? { ready: true } : { error_code: reply.error.code };
}

export function scheduleControl(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action = localControl,
) {
  return {
    type: "@nckrtl/schedule-control",
    version: "2026.10.30.2",
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
            (input) => action(args.kind, Input.parse(input)),
            (result) => Result.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = scheduleControl();
