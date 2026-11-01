import { z } from "npm:zod@4.4.3";
import { Firewall, Role } from "./role_direct_contract.ts";
import { removeRoleRuntime, RoleRuntimeRemoval } from "./role_runtime_local.ts";
import { RolePublication } from "./role_publication.ts";
import { RoleCertificates } from "./role_certificates.ts";
import { ProxyNodeIds, RoleMetricsPlan } from "./role_metrics.ts";
import { convergeFirewall } from "../routes/firewall-local.ts";
import { removeRoleCertificate } from "../certificates/role-publication.ts";
import { revokeMetricsAccess } from "../integrations/metrics_certificate_system.ts";
import { convergeCiHost } from "./ci_host_system.ts";
import { gatewayRoleSystem } from "./gateway_role_system.ts";

export const RoleRemoval = z.object({
  proxy_node_ids: ProxyNodeIds.optional(),
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  assignment_id: z.number().int().positive(),
  role: Role,
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  purge_data: z.boolean(),
  offline: z.literal(false),
  publication: RolePublication,
  certificates: RoleCertificates,
  metrics_fleet: RoleMetricsPlan.nullable(),
  runtime: RoleRuntimeRemoval.nullable(),
  caddy: z.record(z.string(), z.unknown()).nullable(),
  firewall: Firewall.nullable(),
  public_ssh: Firewall.nullable(),
}).strict();
export const RemovalStage = z.enum([
  "public-ssh",
  "runtime",
  "ci-host",
  "gateway-dns",
  "firewall",
  "certificates",
]);
const Code = z.string().regex(/^[a-z][a-z0-9_.-]{1,99}$/);
const Result = z.union([
  z.object({}).passthrough(),
  z.object({ error_code: Code }).passthrough(),
]);
const directory = (scope: string) => {
  if (!["role-metrics", "role-websocket", "role-analytics"].includes(scope)) {
    throw new Error("Role withdrawal has no removable certificate scope");
  }
  return "/etc/caddy/orbit-" + scope.slice(5) + "-cert-current";
};
/** Fixed placed source effects only. Shared managers, packages and unrelated runtimes stay. */
export function roleRemovalLocal(deps = {
  firewall: convergeFirewall,
  runtime: removeRoleRuntime,
  ciHost: convergeCiHost,
  gateway: gatewayRoleSystem,
  certificate: removeRoleCertificate,
  revoke: revokeMetricsAccess(),
}) {
  return async (
    raw: unknown,
    stage: z.infer<typeof RemovalStage>,
    signal?: AbortSignal,
    effectNodeId?: number,
    swampProgram?: string,
  ) => {
    const plan = RoleRemoval.parse(raw);
    signal?.throwIfAborted();
    const effectNode = effectNodeId ?? plan.node_id;
    if (stage !== "certificates" && effectNode !== plan.node_id) {
      throw new Error("Role cleanup changed its source Node");
    }
    let rawResult: unknown = {};
    if (stage === "public-ssh" && plan.public_ssh !== null) {
      rawResult = await deps.firewall(plan.public_ssh, undefined, signal);
    } else if (stage === "runtime" && plan.runtime !== null) {
      if (
        plan.runtime.node_id !== plan.node_id || plan.runtime.role !== plan.role
      ) {
        throw new Error("Role runtime withdrawal changed its admitted Node");
      }
      rawResult = await deps.runtime(
        plan.runtime,
        signal,
        undefined,
        undefined,
        undefined,
        swampProgram,
      );
    } else if (stage === "ci-host" && plan.role === "ci-runner") {
      const result = await deps.ciHost(
        plan.purge_data ? "purge" : "remove",
        undefined,
        signal,
      );
      if (
        "outcome" in result && !["removed", "retained"].includes(result.outcome)
      ) {
        return { ok: false, error_code: "ci_runner.host_occupied" };
      }
      rawResult = result;
    } else if (stage === "gateway-dns" && plan.role === "gateway") {
      const result = await deps.gateway(
        { operation: "remove" },
        undefined,
        signal,
      );
      if (!result.completed) {
        return { ok: false, error_code: result.error_code };
      }
    } else if (stage === "firewall" && plan.firewall !== null) {
      rawResult = await deps.firewall(plan.firewall, undefined, signal);
    } else if (stage === "certificates") {
      for (const entry of plan.certificates.withdrawals) {
        if (entry.node_id !== effectNode) continue;
        if (entry.scope === "role-metrics") await deps.revoke(signal);
        const result = await deps.certificate(
          { directory: directory(entry.scope) },
          undefined,
          signal,
        );
        if ("error_code" in result) {
          return { ok: false, error_code: Code.parse(result.error_code) };
        }
      }
    }
    const result = Result.parse(rawResult);
    return "error_code" in result
      ? { ok: false, error_code: Code.parse(result.error_code) }
      : { ok: true };
  };
}
