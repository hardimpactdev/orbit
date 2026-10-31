import { z } from "npm:zod@4.4.3";
import {
  Applied,
  Presence,
  Request,
} from "../../../extensions/behaviors/operations/certificates/input.ts";
import { CaddyPublication } from "../../../extensions/behaviors/operations/routes/caddy.ts";
import { DnsPublication } from "../../../extensions/behaviors/operations/routes/dns.ts";
import { gatewayHttp } from "../../../extensions/behaviors/automatic-deploy/gateway.ts";
export function participantHttp(
  ctx: { globalArgs: { gatewayUrl: string; caFile: string } },
  node: number,
) {
  const http = gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile);
  return (
    method: "GET" | "POST" | "PUT" | "PATCH",
    path: string,
    body?: unknown,
  ) =>
    http(
      method,
      path + (method === "GET"
        ? (path.includes("?") ? "&" : "?") + "participant=" + node
        : ""),
      body,
    );
}
async function effect(name: string, input: unknown = {}) {
  const http = gatewayHttp(
    Deno.env.get("ANALYTICS_TEST_GATEWAY")!,
    Deno.env.get("ANALYTICS_TEST_CA")!,
  );
  const response = await http("POST", "/fixture/effect", { name, input });
  if (response.status !== 200) throw new Error("fixture.effect_failed");
  return response.body as Record<string, unknown>;
}
const Ready = z.union([
  z.object({ ready: z.literal(true) }),
  z.object({ error_code: z.string() }),
]);
export const certificates = {
  request: async (input: unknown) =>
    Request.parse(await effect("certificate.request", input)),
  publish: async (input: unknown) =>
    Applied.parse(await effect("certificate.publish", input)),
  inspect: async (input: unknown) =>
    Presence.parse(await effect("certificate.inspect", input)),
  remove: async (input: unknown) =>
    Applied.parse(await effect("certificate.remove", input)),
};
export const verify = async (input: unknown) =>
  Ready.parse(await effect("private-hop.verify", input));
export const lan = async (input: unknown) =>
  Ready.parse(await effect("lan.remove", input));
export const publishCaddy = async (raw: unknown) => {
  const input = CaddyPublication.parse(raw);
  if ("error_code" in input) return input;
  await effect("caddy.publish", input);
  return {
    ready: true as const,
    result: "published" as const,
    version: input.caddy.version,
  };
};
export const publishPrivateDns = async (raw: unknown) => {
  const input = DnsPublication.parse(raw);
  await effect("private-dns.publish", input);
  return {
    ready: true as const,
    outcome: "published" as const,
    data: { ready: true as const, digest: input.digest },
  };
};
export const managedFirewall = async (input: unknown) =>
  z.union([
    z.object({ matches: z.literal(true) }),
    z.object({ error_code: z.string() }),
  ]).parse(await effect("firewall.publish", input));
export const grace = async () => {
  await effect("dns.grace");
};

export const signCertificateBatch = async (
  leaves: unknown,
  requests: unknown,
) =>
  z.object({
    certificates: z.array(
      z.object({
        node_id: z.number(),
        scope: z.string(),
        certificate_base64: z.string(),
        root_certificate_base64: z.string(),
      }),
    ),
  }).parse(
    await effect("certificate.sign", { leaves, requests }),
  );
