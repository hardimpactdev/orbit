import {
  type Family,
  type FamilyReport,
  type NodeIntent,
  report,
} from "./doctor_contract.ts";

export type DoctorNodeReport = {
  node_id: number;
  node_name: string;
  healthy: boolean;
  families: FamilyReport[];
};

type Owners = Map<Family, Map<string, number>>;

function resourceKey(value: unknown): string | null {
  return typeof value === "number" || typeof value === "string"
    ? String(value)
    : null;
}

function owners(nodes: NodeIntent[]): Owners {
  const admitted = new Set(nodes.map((node) => node.id));
  const instances = new Map<string, number>();
  const routes = new Map<string, number>();
  const candidates = new Map<string, Set<number>>();
  for (const node of nodes) {
    for (const row of node.resources.instances ?? []) {
      const key = resourceKey(row.id);
      if (key !== null) instances.set(key, node.id);
    }
    for (const row of node.resources.routes ?? []) {
      const key = resourceKey(row.id);
      if (key === null) continue;
      // A custom proxy records its workload owner; other Routes belong to their serving Node.
      const owner =
        typeof row.owner_node_id === "number" && admitted.has(row.owner_node_id)
          ? row.owner_node_id
          : node.id;
      if (!routes.has(key)) routes.set(key, owner);
    }
    for (const row of node.resources.application_route_checks ?? []) {
      if (
        row.family !== "route" || typeof row.owner_node_id !== "number" ||
        !admitted.has(row.owner_node_id)
      ) continue;
      const key = resourceKey(row.id);
      if (key === null) continue;
      const peers = candidates.get(key) ?? new Set<number>();
      peers.add(row.owner_node_id);
      candidates.set(key, peers);
    }
  }
  for (const [key, peers] of candidates) {
    // Different workload Nodes can inspect one Route. Its admitted serving row remains authoritative.
    if (!routes.has(key) && peers.size === 1) routes.set(key, [...peers][0]);
  }
  return new Map<Family, Map<string, number>>([
    ["instance", instances],
    ["route", routes],
  ]);
}

function copied(family: FamilyReport): FamilyReport {
  return {
    ...family,
    issues: [],
    observed_resources: [...family.observed_resources],
    observed_checks: {},
    observed_inventories: Object.fromEntries(
      Object.entries(family.observed_inventories).map((
        [key, ids],
      ) => [key, [...ids]]),
    ),
  };
}

/** Merge local observations using immutable ownership, without reading or rechecking the Gateway. */
export function mergeReports(
  admitted: NodeIntent[],
  native: DoctorNodeReport[],
): DoctorNodeReport[] {
  const ownership = owners(admitted);
  const merged = native.map((node) => ({
    ...node,
    families: node.families.map(copied),
  }));
  const byNode = new Map(merged.map((node) => [node.node_id, node]));
  function destination(
    source: DoctorNodeReport,
    family: Family,
    resource: unknown,
  ): FamilyReport {
    const key = resourceKey(resource);
    const owner = key === null ? undefined : ownership.get(family)?.get(key);
    const node = (owner === undefined ? undefined : byNode.get(owner)) ??
      byNode.get(source.node_id)!;
    let target = node.families.find((item) => item.family === family);
    if (!target) {
      target = report(family, 0, []);
      node.families.push(target);
    }
    return target;
  }
  for (const source of native) {
    for (const family of source.families) {
      const local = byNode.get(source.node_id)!.families.find((item) =>
        item.family === family.family
      )!;
      // Related-Node observations cannot stand in for a complete observation of an owned resource.
      local.observed_resources = local.observed_resources.filter((resource) => {
        const key = resourceKey(resource);
        const owner = key === null
          ? undefined
          : ownership.get(family.family)?.get(key);
        return owner === undefined || owner === source.node_id;
      });
      for (const finding of family.issues) {
        destination(source, family.family, finding.resource_id).issues.push({
          ...finding,
        });
      }
      for (const [resource, codes] of Object.entries(family.observed_checks)) {
        const target = destination(source, family.family, resource);
        target.observed_checks[resource] = [
          ...new Set([...(target.observed_checks[resource] ?? []), ...codes]),
        ];
      }
    }
  }
  for (const node of merged) {
    for (const family of node.families) {
      const seen = new Set<string>();
      family.issues = family.issues.filter((finding) => {
        const key = JSON.stringify([
          family.family,
          resourceKey(finding.resource_id),
          finding.code,
          finding.kind,
        ]);
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
      family.status =
        report(family.family, family.checked, family.issues).status;
    }
    node.healthy = node.families.every((family) => family.status === "healthy");
  }
  return merged;
}
