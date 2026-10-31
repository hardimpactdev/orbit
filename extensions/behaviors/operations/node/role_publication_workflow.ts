/** Same-root participant jobs, shared by add/converge/removal/relocation. */
type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const root = Object.fromEntries(
  ["operation_id", "node_id", "kind"].map((
    key,
  ) => [key, expr(`inputs.${key}`)]),
);
const args = { ...root, run_id: expr("run.id") };
const dependency = (job: string) => ({
  job,
  condition: { type: "succeeded" },
});

/** Certificates and serving-state projection must precede afterJob. Never creates another run. */
export function rolePublicationJobs(
  afterJob: string,
  options: {
    stages?: readonly string[];
    prefix?: string;
    blocked?: string;
    /** Relocation publishes the target first, then withdraws the source after target readiness. */
    excludeNodeId?: string;
  } = {},
) {
  const prefix = options.prefix ?? "role_publication";
  const inventory = `${prefix}_inventory`;
  const rootTask = (methodName: string) => ({
    type: "behavior",
    behaviorType: "@nckrtl/node-role-publication",
    behaviorName: expr('"node-role-publication-" + string(inputs.node_id)'),
    methodName,
    inputs: args,
  });
  const jobs: Fields[] = [{
    name: `${inventory}_job`,
    dependsOn: [dependency(afterJob)],
    steps: [{
      name: inventory,
      writes: true,
      ...(options.blocked ? { guard: expr(options.blocked) } : {}),
      task: rootTask("inventory"),
    }],
  }];
  let previous = `${inventory}_job`;
  for (
    const [stage, field, method] of [
      ["php", "php_node_ids", "publishPhp"],
      ["caddy", "caddy_node_ids", "publishCaddy"],
      ["firewall", "caddy_node_ids", "publishFirewall"],
      ["dns", "dns_node_ids", "publishDns"],
    ]
  ) {
    if (options.stages && !options.stages.includes(stage)) continue;
    const name = `${prefix}_${stage}`;
    let members = `steps.${inventory}.outputs.${field}`;
    if (options.excludeNodeId && stage !== "dns") {
      members += `.filter(node, node != ${options.excludeNodeId})`;
    }
    jobs.push({
      name: `${name}_job`,
      dependsOn: [dependency(previous)],
      steps: [{
        name: `${name}_` + expr("self.participant"),
        writes: true,
        target: expr('"orbit-node-" + string(self.participant)'),
        labels: { node: expr("string(self.participant)") },
        forEach: { item: "participant", in: expr(members) },
        ...(options.blocked ? { guard: expr(options.blocked) } : {}),
        task: {
          type: "behavior",
          behaviorType: "@nckrtl/route-publication",
          behaviorName: expr(
            '"route-publication-node-" + string(self.participant)',
          ),
          methodName: method,
          inputs: { ...args, target_node_id: expr("self.participant") },
        },
      }],
    });
    previous = `${name}_job`;
    if (stage === "caddy") {
      const state = prefix + "_serving_state";
      jobs.push({
        name: state + "_job",
        dependsOn: [dependency(previous)],
        steps: [{
          name: state,
          writes: true,
          ...(options.blocked ? { guard: expr(options.blocked) } : {}),
          task: rootTask("served"),
        }],
      });
      previous = state + "_job";
    }
  }
  const complete = `${prefix}_complete`;
  jobs.push({
    name: `${complete}_job`,
    dependsOn: [dependency(previous)],
    steps: [{
      name: complete,
      writes: true,
      ...(options.blocked ? { guard: expr(options.blocked) } : {}),
      task: rootTask("complete"),
    }],
  });
  return {
    jobs,
    lastJob: `${complete}_job`,
    result:
      `has(steps.${complete}) && has(steps.${complete}.outputs) ? steps.${complete}.outputs : null`,
  };
}
