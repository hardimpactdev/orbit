/** Shared same-root CSR -> control signer -> fixed-pair publication jobs. */
type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const args = Object.fromEntries([
  ...["operation_id", "node_id", "kind"].map((
    key,
  ) => [key, expr(`inputs.${key}`)]),
  ["run_id", expr("run.id")],
]);
const dependency = (job: string) => ({
  job,
  condition: { type: "succeeded" },
});

/** Canonical certificate model configuration owns signer vault Globals and CA custody. */
export function roleCertificateJobs(
  afterJob: string,
  options: { prefix?: string; blocked?: string } = {},
) {
  const prefix = options.prefix ?? "role_certificates";
  if (!/^[a-z][a-z0-9_]*$/.test(prefix)) {
    throw new Error("Invalid role certificate job prefix");
  }
  const guard = options.blocked ? { guard: expr(options.blocked) } : {};
  const inventory = `${prefix}_inventory`;
  const request = `${prefix}_request_`;
  const sign = `${prefix}_sign`;
  const publish = `${prefix}_publish_`;
  const record = `${prefix}_record_`;
  const complete = `${prefix}_complete`;
  const plan = `steps.${inventory}.outputs`;
  const leaves =
    `has(steps.${inventory}) && has(${plan}) ? ${plan}.leaves : []`;
  const nodes =
    `has(steps.${inventory}) && has(${plan}) ? ${plan}.node_ids : []`;
  const key = (leaf: string) => `string(${leaf}.node_id) + "_" + ${leaf}.scope`;
  const requestFor = (leaf: string) =>
    `steps["${request}" + ${key(leaf)}].outputs.request`;
  const rootTask = (methodName: string, extra: Fields = {}) => ({
    type: "behavior",
    behaviorType: "@nckrtl/node-role-certificates",
    behaviorName: expr('"node-role-certificates-" + string(inputs.node_id)'),
    methodName,
    inputs: { ...args, ...extra },
  });
  const placed = {
    target: expr('"orbit-node-" + string(self.leaf.node_id)'),
    labels: { node: expr("string(self.leaf.node_id)") },
    forEach: { item: "leaf", in: expr(leaves) },
  };
  const certificateTask = (methodName: string, extra: Fields) => ({
    type: "behavior",
    behaviorType: "@nckrtl/certificates",
    behaviorName: expr('"certificates-node-" + string(self.leaf.node_id)'),
    methodName,
    inputs: {
      ...args,
      target_node_id: expr("self.leaf.node_id"),
      leaf: expr("self.leaf"),
      ...extra,
    },
  });
  const signed = `steps.${sign}.outputs.certificates.filter(c, ` +
    "c.node_id == self.leaf.node_id && c.scope == self.leaf.scope)[0]";
  const signature = `${requestFor("self.leaf")}.state == "current" ? ` +
    '{"skipped": true} : ' +
    `{"certificate_base64": ${signed}.certificate_base64, ` +
    `"root_certificate_base64": ${signed}.root_certificate_base64}`;
  const requests = `(${leaves}).map(leaf, [` +
    `${requestFor("leaf")}.state == "current" ? ` +
    '{"node_id": leaf.node_id, "scope": leaf.scope, "state": "current"} : ' +
    '{"node_id": leaf.node_id, "scope": leaf.scope, "state": "request", ' +
    `"request_base64": ${requestFor("leaf")}.request_base64}])`;
  const jobs: Fields[] = [{
    name: `${inventory}_job`,
    dependsOn: [dependency(afterJob)],
    steps: [{
      name: inventory,
      writes: true,
      ...guard,
      task: rootTask("inventory"),
    }],
  }, {
    name: `${prefix}_requests_job`,
    dependsOn: [dependency(`${inventory}_job`)],
    steps: [{
      name: request + expr(key("self.leaf")),
      writes: true,
      ...placed,
      ...guard,
      task: certificateTask("roleRequest", {}),
    }],
  }, {
    name: `${sign}_job`,
    dependsOn: [dependency(`${prefix}_requests_job`)],
    steps: [{
      name: sign,
      writes: true,
      target: "orbit-node-209",
      labels: { node: "209" },
      guard: expr(
        `(${
          options.blocked ? options.blocked + " || " : ""
        }(${leaves}).size() == 0)`,
      ),
      task: {
        type: "behavior",
        behaviorType: "@nckrtl/certificates",
        behaviorName: "orbit-certificate-signing",
        methodName: "signBatch",
        inputs: { ...args, leaves: expr(leaves), requests: expr(requests) },
      },
    }],
  }, {
    name: `${prefix}_publish_job`,
    dependsOn: [dependency(`${sign}_job`)],
    steps: [{
      name: publish + expr(key("self.leaf")),
      writes: true,
      ...placed,
      ...guard,
      task: certificateTask("rolePublish", {
        request: expr(requestFor("self.leaf")),
        signed: expr(signature),
      }),
    }],
  }, {
    name: `${prefix}_record_job`,
    dependsOn: [dependency(`${prefix}_publish_job`)],
    steps: [{
      name: record + expr("string(self.participant)"),
      writes: true,
      target: expr('"orbit-node-" + string(inputs.node_id)'),
      labels: { node: expr("string(inputs.node_id)") },
      forEach: { item: "participant", in: expr(nodes) },
      ...guard,
      task: rootTask("record", {
        target_node_id: expr("self.participant"),
      }),
    }],
  }, {
    name: `${complete}_job`,
    dependsOn: [dependency(`${prefix}_record_job`)],
    steps: [{
      name: complete,
      writes: true,
      ...guard,
      task: rootTask("complete"),
    }],
  }];
  return {
    jobs,
    lastJob: `${complete}_job`,
    result:
      `has(steps.${complete}) && has(steps.${complete}.outputs) ? steps.${complete}.outputs : null`,
  };
}
