import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({
  kind: z.enum(["route.workload_verify", "route.public_verify"]),
  node_id: z.number().int().positive(),
});
export const Probe = z.object({
  protocol: z.enum(["tls", "http", "skip"]),
  agent_sha256: z.string().regex(/^[0-9a-f]{64}$/),
  probe: z.object({
    address: z.union([z.ipv4(), z.ipv6()]),
    port: z.number().int().min(1).max(65535),
    domain: z.string().min(1).max(253),
  }).strict().nullable(),
}).strict().refine((input) =>
  (input.protocol === "skip") === (input.probe === null)
);
const Outcome = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), data: z.object({}) }),
  z.object({
    ok: z.literal(false),
    error: z.object({ code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }),
  }),
]);

/** Two fixed account-only probes, no root boundary or worker-selected command. */
export async function verifyLocal(input: unknown) {
  const admitted = Probe.parse(input);
  if (admitted.protocol === "skip") return { ready: true };
  const command = admitted.protocol === "tls"
    ? "route.verify-certificate"
    : "route.verify-http";
  const child = new Deno.Command("/usr/local/bin/orbit-agent", {
    args: ["command", command],
    clearEnv: true,
    env: { PATH: "/usr/local/bin:/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(
    JSON.stringify({
      agent_sha256: admitted.agent_sha256,
      deadline_ms: 15000,
      input: admitted.probe,
    }) + "\n",
  ));
  await writer.close();
  const output = await child.output();
  const outcome = Outcome.parse(
    JSON.parse(new TextDecoder().decode(output.stdout)),
  );
  if (!outcome.ok) return { error_code: outcome.error.code };
  if (!output.success) {
    throw new Error("Route verification returned a contradictory result");
  }
  return { ready: true };
}

type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function verification(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action: (input: z.infer<typeof Probe>) => Promise<unknown> = verifyLocal,
) {
  return {
    type: "@nckrtl/route-verification",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      verify: {
        description:
          "Verify the admitted Route hop on its exact forwarding Node",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) =>
          await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (input) => action(Probe.parse(input)),
            (result) => {
              if (result.error_code === undefined && result.ready !== true) {
                throw new Error("Route verification has no ready result");
              }
              return result.error_code === undefined
                ? { ready: true }
                : { error_code: result.error_code };
            },
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = verification();
