import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

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

/** Two fixed account-only probes, no root boundary or worker-selected command. */
export async function verifyLocal(
  input: unknown,
  invoke?: (call: NodeCall) => Promise<Record<string, unknown>>,
) {
  const admitted = Probe.parse(input);
  if (!invoke) {
    const { address, port, domain } = admitted.probe;
    if (
      !/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/
        .test(domain)
    ) throw new Error("Route probe domain is invalid");
    const endpoint = address.includes(":")
      ? `[${address}]:${port}`
      : `${address}:${port}`;
    const process = admitted.protocol === "tls"
      ? new Deno.Command("/usr/bin/timeout", {
        args: [
          "10",
          "/usr/bin/openssl",
          "s_client",
          "-connect",
          endpoint,
          "-servername",
          domain,
          "-verify_hostname",
          domain,
          "-verify_return_error",
        ],
        stdin: "null",
        stdout: "null",
        stderr: "null",
        signal: AbortSignal.timeout(15000),
      })
      : new Deno.Command("/usr/bin/curl", {
        args: [
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
        ],
        stdin: "null",
        stdout: "null",
        stderr: "null",
        signal: AbortSignal.timeout(15000),
      });
    try {
      return (await process.output()).success ? { ready: true } : {
        error_code: admitted.protocol === "tls"
          ? "route.certificate_invalid"
          : "route.http_unreachable",
      };
    } catch {
      return {
        error_code: admitted.protocol === "tls"
          ? "route.certificate_invalid"
          : "route.http_unreachable",
      };
    }
  }
  const result = await invoke({
    name: admitted.protocol === "tls"
      ? "route.verify-certificate"
      : "route.verify-http",
    input: admitted.probe,
    pin: admitted.agent_sha256,
    sudo: false,
    mutates: false,
    deadlineMs: 15000,
    maxOutputBytes: 4096,
  });
  return result.error_code === undefined ? { ready: true } : {
    error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
      result.error_code,
    ),
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
    version: "2026.10.31.2",
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
