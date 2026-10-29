import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { Completion, ErrorCode, ResultObject } from "./contract.ts";
import { NodeExecution } from "./execution.ts";

const Common = z.object({
  key: z.string().regex(/^[a-z0-9_.-]+$/),
  optional: z.boolean(),
});
const Run = Common.extend({
  type: z.literal("run"),
  transport: z.enum(["gateway", "agent", "bootstrap-ssh"]),
  name: z.enum([
    "node.begin-removal",
    "node.restore-removal-state",
    "agent.remove",
    "firewall.rules",
    "firewall.router-lan",
    "wireguard.repair-peer-dns",
    "node.bootstrap",
    "agent.status",
    "node.inspect-macos-enrollment",
    "node.begin-enrollment",
    "node.activate-enrollment",
    "node.restore-enrollment-state",
    "wireguard.commit-peer",
    "node.prepare-storage-root",
  ]),
  input: ResultObject,
  result: ResultObject,
}).strict();
const Child = Common.extend({
  type: z.literal("child"),
  kind: z.enum([
    "node.role_remove",
    "node.removal_begin",
    "node.removal_restore",
    "node.wireguard_hub_converge",
    "node.network_projection",
    "route.caddy_converge",
    "route.offline_residue_clean",
    "route.private_dns_publish",
    "metrics.reconcile",
    "node.enrollment_begin",
    "node.enrollment_activate",
    "node.enrollment_commit",
    "node.enrollment_restore",
    "node.enrollment_settings",
    "node.bootstrap_probe",
    "node.bootstrap_install",
    "node.bootstrap_host",
    "node.bootstrap_verify",
    "node.macos_inspect",
    "node.network_converge",
    "node.network_rollback",
    "node.role_add",
    "node.converge",
    "fleet.cli_converge",
    "tool.manager_converge",
    "route.update",
  ]),
  node_id: z.number().int().positive(),
  input: ResultObject,
}).strict();
const Plan = z.array(z.discriminatedUnion("type", [Run, Child])).max(4096);
const ChildReceipt = z.object({
  id: z.number().int().positive(),
  key: z.string(),
  kind: z.string(),
  node_id: z.number().int().positive(),
  status: z.enum([
    "queued",
    "running",
    "waiting",
    "succeeded",
    "failed",
    "cancelled",
  ]),
});

/** Runs the recorded lifecycle plan; Gateway proof independently controls every stage. */
export async function lifecycle(
  execution: NodeExecution,
): Promise<z.infer<typeof Completion> | { waiting: true }> {
  const outcome = (
    result: Record<string, unknown>,
  ): z.infer<typeof Completion> => {
    const error = result.error_code ? ErrorCode.parse(result.error_code) : null;
    if (result.node_plan_proven !== true) {
      throw new ExecutorError("operation.completion_unproven");
    }
    return {
      status: error === "operation.cancelled"
        ? "cancelled"
        : error
        ? "failed"
        : "succeeded",
      result,
      error_code: error,
    };
  };
  const finish = async (): Promise<
    z.infer<typeof Completion> | { waiting: true }
  > => {
    await execution.refresh();
    let children = z.array(ChildReceipt).parse(await execution.get("children"));
    if (execution.operation.cancel_requested) {
      const pending = children.filter((child) =>
        ["queued", "running", "waiting"].includes(child.status)
      );
      if (pending.length) {
        await execution.wait(pending.map((child) => child.key));
        return { waiting: true };
      }
    }
    if (
      execution.operation.execution_context?.result?.node_restore_required ===
        true
    ) {
      const restoringEnrollment = execution.operation.kind === "node.add";
      const restoreKey = restoringEnrollment ? "add-restore" : "remove-restore";
      const restoreKind = restoringEnrollment
        ? "node.enrollment_restore"
        : "node.removal_restore";
      let restore = children.find((child) => child.key === restoreKey);
      if (!restore) {
        await execution.child(restoreKey);
        children = z.array(ChildReceipt).parse(await execution.get("children"));
        restore = children.find((child) => child.key === restoreKey);
      }
      if (
        !restore || restore.kind !== restoreKind ||
        restore.node_id !== execution.operation.node_id
      ) {
        throw new ExecutorError("operation.completion_unproven");
      }
      if (["queued", "running", "waiting"].includes(restore.status)) {
        await execution.wait([restore.key]);
        return { waiting: true };
      }
      await execution.refresh();
    }
    if (execution.operation.cancel_requested) {
      const { node_restore_required: _restore, ...result } = ResultObject.parse(
        execution.operation.execution_context?.result,
      );
      return outcome(result);
    }
    return outcome(
      await execution.run(
        "node-lifecycle-result",
        "gateway",
        "node.lifecycle-result",
        {},
      ),
    );
  };
  const plan = Plan.parse(execution.operation.input._node_plan);
  for (const step of plan) {
    await execution.refresh();
    if (execution.operation.cancel_requested) return await finish();
    if (step.type === "run") {
      const result = await execution.run(
        step.key,
        step.transport,
        step.name,
        step.input,
      );
      if (result.error_code && !step.optional) return await finish();
      if (
        !result.error_code &&
        !Object.entries(step.result).every(([key, value]) =>
          result[key] === value
        )
      ) {
        throw new ExecutorError("operation.completion_unproven");
      }
    } else {
      let children = z.array(ChildReceipt).parse(
        await execution.get("children"),
      );
      let child = children.find((entry) => entry.key === step.key);
      if (!child) {
        await execution.child(step.key);
        children = z.array(ChildReceipt).parse(await execution.get("children"));
        child = children.find((entry) => entry.key === step.key);
      }
      if (
        !child || child.kind !== step.kind || child.node_id !== step.node_id
      ) throw new ExecutorError("operation.completion_unproven");
      if (["queued", "running", "waiting"].includes(child.status)) {
        await execution.wait([step.key]);
        return { waiting: true };
      }
      if (child.status !== "succeeded" && !step.optional) return await finish();
    }
  }
  return await finish();
}
