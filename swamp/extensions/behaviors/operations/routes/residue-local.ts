import { z } from "npm:zod@4.4.3";
import { certificateActions } from "../certificates/actions.ts";
import { certificateProgram } from "../certificates/system.ts";
import { convergeFirewall, convergeRouteLan } from "./firewall-local.ts";
import type { runLocalProgram } from "./local-program.ts";

const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
export type ResidueDependencies = {
  action: (call: {
    name: "caddy.publish" | "php.converge-shared-pools";
    input: Record<string, unknown>;
  }, signal?: AbortSignal) => Promise<Record<string, unknown>>;
  account: (user: string) => Promise<{ home: string; group: string }>;
  program: typeof runLocalProgram;
  dns: (
    input: Record<string, unknown>,
    signal?: AbortSignal,
  ) => Promise<Record<string, unknown>>;
};

/** Finish captured residue inside its Node parent's run; state deletion stays with projection. */
export async function cleanRouteResidue(
  raw: unknown,
  snapshot: { node_id: number; agent_sha256: string },
  deps: ResidueDependencies,
  signal?: AbortSignal,
) {
  const input = z.object({
    rows: z.array(
      z.object({
        id: z.number().int().positive(),
        route_id: z.number().int().positive(),
        domain: z.string(),
        steps: z.array(z.string()),
        attempts: z.number().int().nonnegative(),
      }).strict(),
    ).max(10000),
    caddy: z.record(z.string(), z.unknown()),
    user: z.string(),
    php: z.record(z.string(), z.string()).nullable(),
    certificates: z.array(
      z.string().regex(
        /^route-[1-9][0-9]*(?:-router|-router-hostname-change|-ingress)?$/,
      ),
    ),
    firewall: z.array(
      z.object({
        operation: z.literal("remove"),
        route_id: z.number().int().positive(),
      }).strict(),
    ),
    ingress: z.record(z.string(), z.unknown()).nullable(),
    dns: z.record(z.string(), z.unknown()).nullable(),
  }).strict().parse(raw);
  if (input.rows.length === 0) return { changed: false };
  const certificates = certificateActions((call, signal) =>
    deps.program(certificateProgram, call, signal)
  );
  const call = (
    name: "caddy.publish" | "php.converge-shared-pools",
    input: Record<string, unknown>,
  ) => deps.action({ name, input }, signal);
  if (input.rows.some((r) => r.steps.includes("caddy"))) {
    if (input.caddy.error_code) {
      return { error_code: Code.parse(input.caddy.error_code) };
    }
    const result = await call("caddy.publish", input.caddy);
    if ("error_code" in result) return result;
    z.object({ result: z.enum(["published", "unchanged"]) }).parse(result);
  }
  const account = input.php !== null || input.certificates.length
    ? await deps.account(input.user)
    : null;
  if (input.php !== null) {
    const configurations = Object.fromEntries(
      Object.entries(input.php).map((
        [version, text],
      ) => [
        version,
        text.replaceAll("__orbit_converge_group__", account!.group).replaceAll(
          "/__orbit_converge_home__",
          account!.home,
        ),
      ]),
    );
    const result = await call("php.converge-shared-pools", { configurations });
    if ("error_code" in result) return result;
    z.object({ changed: z.boolean() }).parse(result);
  }
  for (const scope of input.certificates) {
    const result = await certificates.remove({
      node_id: snapshot.node_id,
      agent_sha256: snapshot.agent_sha256,
      scope,
      home: account!.home,
      user: input.user,
      in_use: false,
    }, signal);
    if ("error_code" in result) return result;
  }
  for (const rule of input.firewall) {
    const result = await convergeRouteLan(rule, deps.program, signal);
    if ("error_code" in result) return result;
  }
  if (input.ingress !== null) {
    const result = await convergeFirewall(input.ingress, deps.program, signal);
    if ("error_code" in result) return result;
  }
  if (input.dns !== null) {
    const result = await deps.dns(input.dns, signal);
    if ("error_code" in result) return result;
    z.object({ ready: z.literal(true) }).parse(result);
  }
  return { changed: true };
}
