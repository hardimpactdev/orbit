import { z } from "npm:zod@4.4.3";
import { DnsPublication } from "../routes/dns.ts";
const Identity = z.object({
  node_id: z.number().int().positive(),
  user: z.string(),
  agent_sha256: z.string(),
});
export const RouteWithdrawal = z.object({
  schema: z.literal("route.withdrawal.v1"),
  instance_id: z.number().int().positive(),
  route_ids: z.array(z.number().int().positive()),
  nodes: z.array(Identity.extend({
    caddy: z.record(z.string(), z.unknown()),
    certificate_scopes: z.array(z.string()),
    lan_route_ids: z.array(z.number().int().positive()),
  })),
  dns: z.array(DnsPublication),
});
import { DestroyLocal } from "./destroy_contract.ts";
import { DestroyRefusal } from "./destroy_local.ts";

type Withdrawal = z.infer<typeof RouteWithdrawal>;
type Node = Withdrawal["nodes"][number];
const same = (left: unknown, right: unknown) =>
  JSON.stringify(left) === JSON.stringify(right);
function refuse(): never {
  throw new DestroyRefusal("route.withdrawal_invalid");
}

/** Pure composition only. Snapshots never authorize publication after a native lock wait. */
export function composeDestroyRouteSnapshots(
  rawLocal: unknown,
  rawRoutes: unknown,
) {
  const local = DestroyLocal.parse(rawLocal);
  const routes = z.array(RouteWithdrawal).min(1).max(1024).parse(rawRoutes);
  const members = new Map(
    local.members.map((member) => [member.instance_id, member]),
  );
  if (
    routes.length !== members.size ||
    new Set(routes.map((route) => route.instance_id)).size !== members.size
  ) refuse();
  const nodes = new Map<number, Node>();
  const dns = new Map<number, Withdrawal["dns"][number]>();
  const identities = new Map<number, { user: string; agent_sha256: string }>();
  const routeIds = new Set<number>();
  for (const route of routes) {
    const member = members.get(route.instance_id);
    if (
      !member || route.nodes.length === 0 ||
      !route.nodes.some((node) =>
        node.node_id === local.node_id && node.user === member.source.user
      ) ||
      new Set(route.nodes.map((node) => node.node_id)).size !==
        route.nodes.length ||
      new Set(route.dns.map((node) => node.node_id)).size !== route.dns.length
    ) refuse();
    for (const id of route.route_ids) {
      if (routeIds.has(id)) refuse();
      routeIds.add(id);
    }
    for (const node of route.nodes) {
      const identity = { user: node.user, agent_sha256: node.agent_sha256 };
      const known = identities.get(node.node_id);
      if (known && !same(known, identity)) refuse();
      identities.set(node.node_id, identity);
    }
    for (const node of route.nodes) {
      if (node.lan_route_ids.some((id) => !route.route_ids.includes(id))) {
        refuse();
      }
      for (const scope of node.certificate_scopes) {
        const application = /^app-instance-([0-9]+)(?:-hostname-change)?$/.exec(
          scope,
        );
        const routeScope = /^route-([0-9]+)(?:-router(?:-hostname-change)?)?$/
          .exec(scope);
        if (
          application
            ? Number(application[1]) !== route.instance_id ||
              node.node_id !== local.node_id
            : !routeScope || !route.route_ids.includes(Number(routeScope[1]))
        ) refuse();
      }
      const known = nodes.get(node.node_id);
      if (known && !same(known.caddy, node.caddy)) refuse();
      nodes.set(node.node_id, {
        ...node,
        certificate_scopes: [
          ...new Set([
            ...(known?.certificate_scopes ?? []),
            ...node.certificate_scopes,
          ]),
        ].sort(),
        lan_route_ids: [
          ...new Set([...(known?.lan_route_ids ?? []), ...node.lan_route_ids]),
        ].sort((a, b) => a - b),
      });
    }
    for (const node of route.dns) {
      const known = dns.get(node.node_id);
      if (known && !same(known, node)) refuse();
      dns.set(node.node_id, node);
    }
  }
  return {
    nodes: [...nodes.values()].sort((a, b) => a.node_id - b.node_id),
    dns: [...dns.values()].sort((a, b) => a.node_id - b.node_id),
  };
}
