import { roleMetricsJobs } from "./role_metrics_workflow.ts";

type Fields = Record<string, unknown>;
const expr = (s: string) => "${{ " + s + " }}";
const has = (s: string) => `has(steps.${s}) && has(steps.${s}.outputs)`;
const done = (s: string) =>
  `has(steps.${s}) && steps.${s}.status == "succeeded"`;
const args = Object.fromEntries(
  ["operation_id", "kind", "node_id"].map((k) => [k, expr(`inputs.${k}`)]),
);
const root = { ...args, run_id: expr("run.id") };
const admission = "steps.admit.outputs";
const stopped = `!(${has("admit")}) || !${admission}.ready`;
const placed = (id: string) => ({
  target: expr('"orbit-node-" + string(' + id + ")"),
  labels: { node: expr(`string(${id})`) },
});
const dep = (job: string, type = "settled") => ({
  job,
  condition: type === "settled"
    ? { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] }
    : { type },
});
const task = (methodName: string, extra: Fields = {}, admitted = true) => ({
  type: "behavior",
  behaviorType: "@nckrtl/node-removal-direct",
  behaviorName: expr('"node-removal-root-" + string(inputs.node_id)'),
  methodName,
  inputs: {
    ...root,
    ...(admitted ? { admission: expr(admission) } : {}),
    ...extra,
  },
});
const step = (
  name: string,
  method: string,
  extra: Fields = {},
  guard = stopped,
  modelName?: string,
): Fields => ({
  name,
  writes: true,
  ...placed("inputs.node_id"),
  guard: expr(guard),
  task: {
    ...task(method, extra),
    ...(modelName ? { behaviorName: modelName } : {}),
  },
});
const assert = (name: string, condition: string, source: string): Fields => ({
  name,
  ...placed("inputs.node_id"),
  guard: expr(
    `"${source}" in steps ? steps.${source}.status != "succeeded" : true`,
  ),
  task: {
    type: "assert",
    expr: condition,
    message: "node.removal_dependency_failed",
    severity: "high",
  },
});

/** One native run; all failure compensation remains in that run at exact admitted placements. */
export function nodeRemovalWorkflow() {
  const jobs: Fields[] = [{
    name: "admission",
    steps: [{
      name: "admit",
      writes: true,
      ...placed("inputs.node_id"),
      task: task("admit", {}, false),
    }],
  }];
  const serial = (name: string, after: string, steps: Fields[]) =>
    jobs.push({ name, dependsOn: [dep(after)], steps });
  serial("recover", "admission", [{
    ...step(
      "recover_access",
      "recoverAccess",
      {},
      `${stopped} || ${admission}.effective_offline || ${admission}.public_ssh == null`,
    ),
    ...placed(`${admission}.snapshot.node_id`),
  }]);
  serial("withdraw", "recover", [
    step(
      "begin",
      "begin",
      {},
      `${stopped} || (!${admission}.effective_offline && ${admission}.public_ssh != null && !(${
        has("recover_access")
      }))`,
    ),
  ]);
  serial("access", "withdraw", [
    step(
      "revoke_access",
      "revokeAccess",
      {},
      `${stopped} || !(${has("begin")})`,
    ),
  ]);
  const metricsBlocked = `${stopped} || !(${has("revoke_access")})`;
  const metrics = roleMetricsJobs("access", {
    prefix: "removal_metrics",
    blocked: metricsBlocked,
  });
  jobs.push(...metrics.jobs);
  serial("metrics_ready", metrics.lastJob, [
    assert(
      "metrics_ready",
      `steps.removal_metrics_complete.outputs.ready`,
      "removal_metrics_complete",
    ),
  ]);
  const hub = (restoring: boolean, guard: string): Fields[] => {
    const field = restoring ? "restore_hub" : "hub";
    const name = restoring ? "restore_hub" : "hub_publish";
    const input = `${admission}.${field}`;
    return [{
      name,
      writes: true,
      ...placed(
        `(${input} == null ? inputs.node_id : ${input}.executor_node_id)`,
      ),
      guard: expr(`${guard} || ${input} == null`),
      task: {
        type: "behavior",
        behaviorType: "@nckrtl/node-wireguard-direct",
        behaviorName: expr(
          `"wireguard-hub-" + string(${input} == null ? inputs.node_id : ${input}.hub_id)`,
        ),
        methodName: "publish",
        inputs: {
          ...root,
          snapshot: expr(input),
          ...(restoring ? { recovery: true } : {}),
        },
      },
    }, {
      ...assert(
        name + "_ready",
        `steps.${name}.outputs.status == "succeeded"`,
        name,
      ),
      dependsOn: [{ step: name, condition: { type: "succeeded" } }],
    }];
  };
  const hubBlocked = `${metricsBlocked} || !(${done("metrics_ready")})`;
  serial("hub", "metrics_ready", hub(false, hubBlocked));
  const servingBlocked = `${hubBlocked} || (${admission}.hub != null && !(${
    done("hub_publish_ready")
  }))`;
  const participants = (restoring: boolean, guard: string): Fields[] => [{
    ...step(restoring ? "restore_participant" : "participant", "participant", {
      restore: restoring,
      placement: {
        effect_node_id: expr("self.member.node_id"),
        execution_node_id: expr("self.member.node_id"),
        transport: "local",
      },
    }, guard, expr(
      `"node-removal-${restoring ? "restore-participant" : "participant"}-" + run.id + "-" + string(self._index)`,
    )),
    name: (restoring ? "restore_participant_" : "participant_") +
      expr("self.member.node_id"),
    ...placed("self.member.node_id"),
    forEach: {
      item: "member",
      in: expr(`${has("admit")} ? ${admission}.snapshot.participants : []`),
    },
  }];
  serial("serving", "hub", participants(false, servingBlocked));
  const servingReady =
    `${admission}.snapshot.participants.all(member, "participant_"+string(member.node_id) in steps && "outputs" in steps["participant_"+string(member.node_id)] && steps["participant_"+string(member.node_id)].outputs.ready)`;
  serial("retire", "serving", [
    step("guest", "guest", {}, `${servingBlocked} || !(${servingReady})`),
  ]);
  serial("projection", "retire", [
    step("finish", "finish", {}, `${stopped} || !(${has("guest")})`),
  ]);
  const forward = jobs.map((j) => j.name as string);
  const restoreBlocked = `${stopped} || (${has("finish")})`;
  jobs.push({
    name: "restore_begin",
    dependsOn: forward.map((name) => dep(name, "always")),
    steps: [step("restore_begin", "beginRestore", {}, restoreBlocked)],
  });
  serial(
    "restored_hub",
    "restore_begin",
    hub(true, `${restoreBlocked} || !(${has("restore_begin")})`),
  );
  const nodes = `${admission}.restore_certificate_node_ids`;
  // A list of lists lets the canonical signer flatten public requests without moving leaf keys.
  const restoringHubBlocked = `${restoreBlocked} || !(${
    has("restore_begin")
  }) || (${admission}.restore_hub != null && !(${done("restore_hub_ready")}))`;
  const certGuard =
    `${restoringHubBlocked} || ${admission}.restore_certificates.size() == 0`;
  serial("restore_certificate_requests", "restored_hub", [{
    ...step("restore_certificate_request", "requestRestoreCertificates", {
      target_node_id: expr("self.id"),
    }, certGuard, expr(
      '"node-removal-restore-certificate-request-" + run.id + "-" + string(self._index)',
    )),
    name: "restore_certificate_request_" + expr("self.id"),
    ...placed("self.id"),
    forEach: { item: "id", in: expr(`${has("admit")} ? ${nodes} : []`) },
  }]);
  serial("restore_certificate_sign", "restore_certificate_requests", [{
    name: "restore_certificate_sign",
    writes: true,
    target: "orbit-node-209",
    labels: { node: "209" },
    guard: expr(
      `${certGuard} || !(${nodes}).all(id, "restore_certificate_request_"+string(id) in steps && "outputs" in steps["restore_certificate_request_"+string(id)])`,
    ),
    task: {
      type: "behavior",
      behaviorType: "@nckrtl/certificates",
      behaviorName: "orbit-certificate-signing",
      methodName: "signBatch",
      inputs: {
        ...root,
        restoring: true,
        leaves: expr(`${admission}.restore_certificates`),
        requests: expr(
          `(${nodes}).map(id, steps["restore_certificate_request_" + string(id)].outputs.requests)`,
        ),
      },
    },
  }]);
  serial("restore_certificate_publish", "restore_certificate_sign", [{
    ...step("restore_certificate_publish", "publishRestoreCertificates", {
      target_node_id: expr("self.id"),
      requests: expr(
        'steps["restore_certificate_request_" + string(self.id)].outputs.requests',
      ),
      signed: expr("steps.restore_certificate_sign.outputs.certificates"),
    }, `${certGuard} || !(${has("restore_certificate_sign")})`, expr(
      '"node-removal-restore-certificate-publish-" + run.id + "-" + string(self._index)',
    )),
    name: "restore_certificate_publish_" + expr("self.id"),
    ...placed("self.id"),
    forEach: { item: "id", in: expr(`${has("admit")} ? ${nodes} : []`) },
  }]);
  const restoringServingBlocked =
    `${restoringHubBlocked} || !(${nodes}).all(id, "restore_certificate_publish_"+string(id) in steps && "outputs" in steps["restore_certificate_publish_"+string(id)] && steps["restore_certificate_publish_"+string(id)].outputs.ready)`;
  serial(
    "restore_serving",
    "restore_certificate_publish",
    participants(true, restoringServingBlocked),
  );
  // Restore confirmed exporter/service snapshots first, including an unavailable retiring Metrics owner.
  const captured = "steps.removal_metrics_capture.outputs";
  serial("restore_exporters", "restore_serving", [{
    name: "restore_exporter_" + expr("self.id"),
    writes: true,
    ...placed("self.id"),
    forEach: {
      item: "id",
      in: expr(`${has("removal_metrics_capture")} ? ${captured}.ids : []`),
    },
    guard: expr(
      `${restoreBlocked} || !("removal_metrics_snapshot-" + string(self.id) in steps) || !("outputs" in steps["removal_metrics_snapshot-" + string(self.id)]) || !steps["removal_metrics_snapshot-" + string(self.id)].outputs.ok`,
    ),
    task: {
      type: "behavior",
      behaviorType: "@nckrtl/node-role-metrics",
      behaviorName: expr('"node-role-metrics-" + string(self.id)'),
      methodName: "stage",
      inputs: {
        ...root,
        restoring: true,
        phase: "restore",
        node: expr(
          `${captured}.plan.fleet.filter(node, node.node_id == self.id)[0]`,
        ),
        metrics_address: expr(`${captured}.plan.metrics_address`),
        snapshot: expr(
          'steps["removal_metrics_snapshot-" + string(self.id)].outputs',
        ),
      },
    },
  }]);
  const restoredMetrics = roleMetricsJobs("restore_exporters", {
    prefix: "restore_metrics",
    blocked: restoringServingBlocked,
    restoring: true,
  });
  jobs.push(...restoredMetrics.jobs);
  serial("restoration_ready", restoredMetrics.lastJob, [
    assert(
      "restoration_ready",
      `steps.restore_metrics_complete.outputs.ready`,
      "restore_metrics_complete",
    ),
  ]);
  const restoreNames = jobs.filter((j) => !forward.includes(j.name as string))
    .map((j) => j.name as string);
  const participantsReady =
    `${admission}.snapshot.participants.all(member, "restore_participant_"+string(member.node_id) in steps && "outputs" in steps["restore_participant_"+string(member.node_id)] && steps["restore_participant_"+string(member.node_id)].outputs.ready)`;
  const exportersReady = `!(${
    has("removal_metrics_capture")
  }) || ${captured}.ids.all(id, !("removal_metrics_snapshot-"+string(id) in steps) || !("outputs" in steps["removal_metrics_snapshot-"+string(id)]) || !steps["removal_metrics_snapshot-"+string(id)].outputs.ok || ("restore_exporter_"+string(id) in steps && "outputs" in steps["restore_exporter_"+string(id)] && steps["restore_exporter_"+string(id)].outputs.ok))`;
  const retired = `has(steps.guest) && steps.guest.status == "failed"`;
  const restored = `!(${retired}) && (${has("restore_begin")}) && (${
    done("restoration_ready")
  }) && (${participantsReady}) && (${exportersReady})`;
  jobs.push({
    name: "failure",
    dependsOn: restoreNames.map((name) => dep(name, "always")),
    steps: [
      step("failed", "fail", { restored: expr(restored) }, restoreBlocked),
    ],
  });
  return {
    id: "450e57df-e174-4ad6-bba5-306c2581d919",
    name: "operation-node-remove",
    description:
      "Remove one Node and restore serving state on failure in the same native run.",
    inputs: {
      type: "object",
      properties: {
        operation_id: { type: "integer", minimum: 1 },
        node_id: { type: "integer", minimum: 1 },
        kind: { type: "string", enum: ["node.remove"] },
      },
      required: ["operation_id", "node_id", "kind"],
      additionalProperties: false,
    },
    ...placed("inputs.node_id"),
    jobs,
  };
}
if (import.meta.main) {
  console.log(JSON.stringify(nodeRemovalWorkflow(), null, 2));
}
