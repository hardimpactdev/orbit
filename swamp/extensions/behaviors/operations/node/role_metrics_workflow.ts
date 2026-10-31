type Fields = Record<string, unknown>;
const expr = (value: string) => "${{ " + value + " }}";
const dependency = (job: string, type = "succeeded") => ({
  job,
  condition: { type },
});
const root = Object.fromEntries(
  ["operation_id", "node_id", "kind"].map((
    key,
  ) => [key, expr(`inputs.${key}`)]),
);
const args = { ...root, run_id: expr("run.id") };

/** Same native root: snapshot all members, apply, verify, commit, then reconcile confirmed failure. */
export function roleMetricsJobs(
  afterJob: string,
  options: { prefix?: string; blocked?: string; restoring?: boolean } = {},
) {
  const prefix = options.prefix ?? "role_metrics";
  const names = Object.fromEntries(
    [
      "capture",
      "snapshot",
      "apply",
      "service",
      "verify",
      "runtime",
      "restore",
      "complete",
    ]
      .map((stage) => [stage, prefix + "_" + stage]),
  );
  const capture = `steps.${names.capture}.outputs`;
  const plan = `${capture}.plan`;
  const has = (name: string) =>
    `has(steps.${name}) && has(steps.${name}.outputs)`;
  const memberStep = (stage: string, id = "id") =>
    `steps["${names[stage]}-" + string(${id})]`;
  const memberOut = (stage: string, id = "id") =>
    `${memberStep(stage, id)}.outputs`;
  const memberHasOutput = (stage: string, id = "id") =>
    `"outputs" in ${memberStep(stage, id)}`;
  const phaseReady = (stage: string) =>
    `${capture}.ids.all(id, "${names[stage]}-" + string(id) in steps && ${
      memberHasOutput(stage)
    } && ${memberOut(stage)}.ok)`;
  const runtime = `steps.${names.runtime}`;
  const blocked = options.blocked;
  const rootTask = (
    methodName: string,
    extra: Fields = {},
    nodeId = "inputs.node_id",
  ) => ({
    type: "behavior",
    behaviorType: "@nckrtl/node-role-metrics",
    behaviorName: expr('"node-role-metrics-" + string(' + nodeId + ")"),
    methodName,
    inputs: {
      ...args,
      ...(options.restoring ? { restoring: true } : {}),
      ...extra,
    },
  });
  const placed = (nodeId: string) => ({
    target: expr('"orbit-node-" + string(' + nodeId + ")"),
    labels: { node: expr("string(" + nodeId + ")") },
  });
  const stepGuard = (condition?: string) =>
    condition || blocked
      ? {
        guard: expr(
          [blocked, condition].filter(Boolean).map((v) => `(${v})`).join(
            " || ",
          ),
        ),
      }
      : {};
  const jobs: Fields[] = [{
    name: names.capture + "_job",
    dependsOn: [dependency(afterJob)],
    steps: [{
      name: names.capture,
      writes: true,
      ...placed("inputs.node_id"),
      ...stepGuard(),
      task: rootTask("capture"),
    }],
  }, {
    name: prefix + "_availability_job",
    dependsOn: [dependency(names.capture + "_job")],
    steps: [{
      name: prefix + "_availability",
      ...placed("inputs.node_id"),
      ...(blocked ? { guard: expr(blocked) } : {}),
      task: {
        type: "assert",
        expr: `${capture}.available`,
        message: "metrics.no_worker",
        severity: "high",
      },
    }],
  }];
  let previous = prefix + "_availability_job";
  for (const phase of ["snapshot", "apply", "service", "verify"]) {
    const name = names[phase];
    jobs.push({
      name: name + "_job",
      dependsOn: [dependency(previous)],
      steps: [{
        name,
        writes: phase !== "verify",
        forEach: {
          item: "id",
          in: expr(`${has(names.capture)} ? ${capture}.ids : []`),
        },
        ...placed("self.id"),
        ...stepGuard(),
        task: rootTask("stage", {
          phase,
          node: expr(`${plan}.fleet.filter(node, node.node_id == self.id)[0]`),
          metrics_address: expr(`${plan}.metrics_address`),
        }, "self.id"),
      }, {
        name: name + "_ready",
        ...placed("inputs.node_id"),
        ...stepGuard(),
        dependsOn: [{ step: name, condition: { type: "succeeded" } }],
        task: rootTask("checkpoint", {
          phase,
          ready: expr(phaseReady(phase)),
        }),
      }],
    });
    previous = name + "_job";
  }
  jobs.push({
    name: names.runtime + "_job",
    dependsOn: [dependency(previous)],
    steps: [{
      name: names.runtime,
      writes: true,
      ...placed(
        `${
          has(names.capture)
        } && ${plan} != null ? ${plan}.metrics_node_id : inputs.node_id`,
      ),
      ...stepGuard(`!(${has(names.capture)}) || !${capture}.runtime_required`),
      task: rootTask("runtime", {
        node: expr(
          `${plan}.fleet.filter(node, node.node_id == ${plan}.metrics_node_id)[0]`,
        ),
      }, `${plan} != null ? ${plan}.metrics_node_id : inputs.node_id`),
    }],
  });
  // A failed runtime call without output may have committed. Never roll back desired exporters in that case.
  const noRuntimeCommit =
    `(!has(steps.${names.runtime}) || ${runtime}.status == "skipped" || (has(${runtime}.outputs) && !${runtime}.outputs.committed))`;
  const noUncertainMemberEffect = ["apply", "service"].map((stage) =>
    `!("${names[stage]}-" + string(self.id) in steps && ${
      memberStep(stage, "self.id")
    }.status == "failed" && !(${memberHasOutput(stage, "self.id")}))`
  ).join(" && ");
  const snapshot = memberOut("snapshot", "self.id");
  const restoreEligible =
    `${noRuntimeCommit} && (${capture}.runtime_required || !(${
      phaseReady("verify")
    })) && "${names.snapshot}-" + string(self.id) in steps && ${
      memberHasOutput("snapshot", "self.id")
    } && ${snapshot}.ok && "${names.apply}-" + string(self.id) in steps && ${
      memberHasOutput("apply", "self.id")
    } && (${noUncertainMemberEffect})`;
  jobs.push({
    name: names.restore + "_job",
    dependsOn: [dependency(names.runtime + "_job", "always")],
    steps: [{
      name: names.restore,
      writes: true,
      forEach: {
        item: "id",
        in: expr(`${has(names.capture)} ? ${capture}.ids : []`),
      },
      ...placed("self.id"),
      ...stepGuard(`!(${restoreEligible})`),
      task: rootTask("stage", {
        phase: "restore",
        node: expr(`${plan}.fleet.filter(node, node.node_id == self.id)[0]`),
        metrics_address: expr(`${plan}.metrics_address`),
        snapshot: expr(snapshot),
      }, "self.id"),
    }],
  });
  const verified = phaseReady("verify");
  const committed = `(${has(names.runtime)} && ${runtime}.outputs.committed)`;
  const ready = `(${verified}) && (!${capture}.runtime_required || (${
    has(names.runtime)
  } && ${runtime}.outputs.ok))`;
  const error = `!${capture}.available ? "metrics.no_worker" : (${
    has(names.runtime)
  } && has(${runtime}.outputs.error_code) ? ${runtime}.outputs.error_code : "metrics.fleet_failed")`;
  jobs.push({
    name: names.complete + "_job",
    dependsOn: [dependency(names.restore + "_job", "always")],
    steps: [{
      name: names.complete,
      writes: true,
      ...placed("inputs.node_id"),
      ...stepGuard(`!(${has(names.capture)})`),
      task: rootTask("complete", {
        enabled: expr(`${plan} != null`),
        ready: expr(ready),
        committed: expr(committed),
        restored: expr(phaseReady("restore")),
        error_code: expr(`${plan} == null || (${ready}) ? null : (${error})`),
      }),
    }],
  });
  return {
    jobs,
    lastJob: names.complete + "_job",
    result: `${has(names.complete)} ? steps.${names.complete}.outputs : null`,
  };
}
