import { z } from "npm:zod@4.4.3";
import { runLocalProgram } from "./local-program.ts";
import { certificateProgram } from "../certificates/system.ts";
import { certificateActions } from "../certificates/actions.ts";
import { removeLan } from "./lan.ts";

export const RemovalNode = z.object({
  node_id: z.number().int().positive(),
  user: z.string(),
  home: z.string().optional(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  certificate_scopes: z.array(z.string()),
  lan_route_ids: z.array(z.number().int().positive()),
  shared_php: z.boolean(),
  production_instance_ids: z.array(z.number().int().positive()),
  url_instance_ids: z.array(z.number().int().positive()),
  ingress_firewall: z.boolean(),
});
export const RemovalSnapshot = z.object({
  routes: z.array(
    z.object({
      route_id: z.number().int().positive(),
      domain: z.string(),
      replacement_of: z.number().int().positive().nullable(),
    }),
  ),
  nodes: z.array(RemovalNode),
  dns_node_ids: z.array(z.number().int().positive()),
  offline: z.boolean().optional(),
});

function requireSuccess(result: Record<string, unknown>) {
  if (result.error_code !== undefined) {
    throw new Error(
      z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
    );
  }
  return result;
}

/** Lower native actions. The parent DAG orders DNS/Caddy/PHP before these actions. */
export function removalActions(invoke = runLocalProgram) {
  const certificates = certificateActions((call, signal) =>
    invoke(certificateProgram, call, signal)
  );
  return {
    certificates: async (raw: unknown, signal?: AbortSignal) => {
      const node = RemovalNode.parse(raw);
      for (const scope of node.certificate_scopes) {
        const leaf = {
          node_id: node.node_id,
          scope,
          home: node.home,
          user: node.user,
          agent_sha256: node.agent_sha256,
          in_use: false,
        };
        const removed = requireSuccess(await certificates.remove(leaf, signal));
        const inspected = requireSuccess(
          await certificates.inspect(
            {
              node_id: node.node_id,
              scope,
              home: node.home,
              user: node.user,
              agent_sha256: node.agent_sha256,
            },
            removed,
            signal,
          ),
        );
        if (inspected.present !== false) {
          throw new Error("app-dev.certificate_removal_failed");
        }
      }
      return { ready: true };
    },
    lan: async (raw: unknown) => {
      const node = RemovalNode.parse(raw);
      for (const route_id of node.lan_route_ids) {
        requireSuccess(
          await removeLan(
            { route_id },
            invoke,
          ),
        );
      }
      return { ready: true };
    },
  };
}
