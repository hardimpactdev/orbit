import { type NodeCommand, run } from "../_lib/node-commands.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
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
  protocol: z.enum(["tls", "http"]),
  agent_sha256: z.string().regex(/^[0-9a-f]{64}$/),
  probe: z.object({
    address: z.union([z.ipv4(), z.ipv6()]),
    port: z.number().int().min(1).max(65535),
    domain: z.string().min(1).max(253),
  }).strict(),
}).strict();

/** Fixed account-only probes on the placed Node, including its canonical SSH proxy. */
export async function verifyLocal(input: unknown, command: NodeCommand = run) {
  const admitted = Probe.parse(input);
  const { address, port, domain } = admitted.probe;
  if (
    !/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/
      .test(domain)
  ) throw new Error("Route probe domain is invalid");
  const endpoint = address.includes(":")
    ? `[${address}]:${port}`
    : `${address}:${port}`;
  const argv = admitted.protocol === "tls"
    ? [
      "/usr/bin/timeout",
      "10",
      "/usr/bin/openssl",
      "s_client",
      "-quiet",
      "-no_ign_eof",
      "-connect",
      endpoint,
      "-servername",
      domain,
      "-verify_hostname",
      domain,
      "-verify_return_error",
    ]
    : [
      "/usr/bin/curl",
      "--head",
      "--noproxy",
      "*",
      "--silent",
      "--connect-timeout",
      "10",
      "--max-time",
      "10",
      "--output",
      "/dev/null",
      "--header",
      `Host: ${domain}`,
      `http://${endpoint}/`,
    ];
  try {
    const result = await command(argv, undefined, "node", {
      deadlineMs: 15000,
      maxOutputBytes: 4096,
    });
    if (result.success) return { ready: true };
  } catch { /* Uncertain probes never grant readiness. */ }
  return {
    error_code: admitted.protocol === "tls"
      ? "route.certificate_invalid"
      : "route.http_unreachable",
  };
}

type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function verification(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  action: (input: z.infer<typeof Probe>) => Promise<unknown> = verifyLocal,
) {
  return {
    type: "@nckrtl/route-verification",
    version: "2026.10.31.3",
    globalArguments: Globals,
    methods: {
      verify: {
        description:
          "Verify the admitted Route endpoint from the control worker",
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
