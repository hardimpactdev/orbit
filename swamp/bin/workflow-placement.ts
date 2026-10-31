type Scope = Record<string, unknown>;

/** Placement fields inherit independently, from the nearest scope outward. */
export function effectivePlacement(scopes: Scope[]): Scope {
  return {
    target: scopes.find((scope) => scope.target !== undefined)?.target,
    labels: scopes.find((scope) => scope.labels !== undefined)?.labels,
  };
}

/** Accept either selector and retain matching dual selectors during migration. */
export function selectsNode(placement: Scope, node: string): boolean {
  const labels = placement.labels && typeof placement.labels === "object" &&
      !Array.isArray(placement.labels)
    ? placement.labels as Scope
    : {};
  if (placement.target !== undefined) {
    return placement.target === `orbit-node-${node}` &&
      (labels.node === undefined || labels.node === node);
  }
  return labels.node === node;
}
