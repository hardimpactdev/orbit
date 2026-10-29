import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { Completion, ErrorCode, ResultObject } from "./contract.ts";
import { NodeExecution, PendingEffect } from "./execution.ts";

const Role = z.enum([
  "gateway",
  "vpn",
  "router",
  "ingress",
  "app-dev",
  "app-prod",
  "metrics",
  "database",
  "websocket",
  "analytics",
  "ci-runner",
]);
export const RoleInput = z.object({ role: Role, converge: z.boolean() });
const ConvergeAssignmentInput = z.object({
  assignment_id: z.number().int().positive(),
});
const RemoveRoleInput = z.object({
  role: Role,
  purge_data: z.boolean(),
  offline: z.boolean(),
});
const Common = z.object({
  key: z.string().regex(/^[a-z0-9_.-]+$/),
  optional: z.boolean(),
  when: z.object({ key: z.string(), result: ResultObject }).optional(),
});
const Run = Common.extend({
  type: z.literal("run"),
  transport: z.enum(["agent", "gateway"]),
  name: z.enum([
    "node.prepare-storage-root",
    "caddy.install-package",
    "caddy.order-after-vpn",
    "firewall.rules",
    "firewall.router-lan",
    "node.grant-gateway-access",
    "node.transfer-role",
    "wireguard.gateway-dns",
    "wireguard.commit-peer",
    "role.install-prerequisites",
    "recipe.install-javascript",
    "php.install-build",
    "ci-runner.host",
    "hibernation.prepare-directories",
  ]),
  input: ResultObject,
  result: ResultObject,
}).strict();
const Child = Common.extend({
  type: z.literal("child"),
  kind: z.enum([
    "node.network_converge",
    "node.network_rollback",
    "node.role_relocate_source",
    "node.role_relocate_target",
    "node.converge",
    "fleet.cli_converge",
    "route.caddy_converge",
    "route.private_dns_publish",
    "tool.manager_converge",
    "metrics.reconcile",
    "metrics.role_converge",
    "analytics.role_converge",
    "websocket.role_converge",
    "metrics.role_remove",
    "analytics.role_remove",
    "websocket.role_remove",
  ]),
  node_id: z.number().int().positive(),
  input: ResultObject,
}).strict();
export const RolePlan = z.array(z.discriminatedUnion("type", [Run, Child]))
  .max(64);
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
  result: ResultObject.nullable(),
  error_code: ErrorCode.nullable(),
});

/** Runs only recorded role effects; the Gateway independently proves prerequisites and results. */
export async function role(
  execution: NodeExecution,
): Promise<z.infer<typeof Completion> | { waiting: true }> {
  const input = [
      "node.role_converge",
      "node.role_relocate_source",
      "node.role_relocate_target",
    ].includes(execution.operation.kind)
    ? ConvergeAssignmentInput.parse(execution.operation.input)
    : execution.operation.kind === "node.role_remove"
    ? RemoveRoleInput.parse(execution.operation.input)
    : RoleInput.parse(
      execution.operation.kind === "node.role_relocate"
        ? { role: execution.operation.input.role, converge: true }
        : execution.operation.input,
    );
  const outcome = (
    result: Record<string, unknown>,
  ): z.infer<typeof Completion> => {
    const error = result.error_code ? ErrorCode.parse(result.error_code) : null;
    return {
      status: error === "operation.cancelled"
        ? "cancelled"
        : error
        ? "failed"
        : "succeeded",
      error_code: error,
      result,
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
      execution.operation.execution_context?.result?.role_rollback_required ===
        true
    ) {
      let rollback = children.find((child) =>
        child.key === "role-network-rollback"
      );
      if (!rollback) {
        await execution.child("role-network-rollback");
        children = z.array(ChildReceipt).parse(await execution.get("children"));
        rollback = children.find((child) =>
          child.key === "role-network-rollback"
        );
      }
      if (
        !rollback || rollback.kind !== "node.network_rollback" ||
        rollback.node_id !== execution.operation.node_id
      ) {
        throw new ExecutorError("operation.completion_unproven");
      }
      if (["queued", "running", "waiting"].includes(rollback.status)) {
        await execution.wait([rollback.key]);
        return { waiting: true };
      }
      await execution.refresh();
    }
    if (execution.operation.cancel_requested) {
      const result = ResultObject.parse(
        execution.operation.execution_context?.result,
      );
      const { role_rollback_required: _rollback, ...receipt } = result;
      if (receipt.role_proven !== true || !receipt.error_code) {
        throw new ExecutorError("operation.completion_unproven");
      }
      return outcome(receipt);
    }
    return outcome(
      await execution.run("role-result", "gateway", "node.role-result", {}),
    );
  };
  if (
    execution.operation.cancel_requested &&
    execution.operation.steps.length === 0
  ) {
    return { status: "cancelled", result: {}, error_code: null };
  }
  const prepared = await execution.run(
    "prepare-role",
    "gateway",
    "node.prepare-role",
    input,
  );
  if (prepared.error_code) {
    return prepared.error_code === "operation.cancelled"
      ? await finish()
      : outcome(prepared);
  }
  await execution.refresh();
  if (execution.operation.cancel_requested) return await finish();
  const plan = RolePlan.parse(execution.operation.input._role_plan);
  for (const step of plan) {
    if (
      execution.operation.kind === "node.role_relocate_source" &&
      step.key !== "role-relocation-target"
    ) {
      await execution.refresh();
      const waitUntil = execution.operation.execution_context?.result
        ?.role_wait_until;
      if (typeof waitUntil === "number" && Date.now() < waitUntil) {
        throw new PendingEffect();
      }
    }
    if (step.when) {
      const previous = ResultObject.parse(
        execution.operation.step_results[step.when.key],
      );
      if (
        !Object.entries(step.when.result).every(([key, value]) =>
          previous[key] === value
        )
      ) continue;
    }
    if (step.type === "run") {
      const result = await execution.run(
        step.key,
        step.transport,
        step.name,
        step.input,
      );
      if (result.error_code && !step.optional) {
        return await finish();
      }
      if (
        !result.error_code &&
        !Object.entries(step.result).every(([key, value]) =>
          result[key] === value
        )
      ) {
        throw new ExecutorError("operation.completion_unproven");
      }
      if (
        step.name === "php.install-build" && !result.error_code &&
        !["applied", "current"].includes(String(result.outcome))
      ) {
        throw new ExecutorError("operation.completion_unproven");
      }
    } else {
      let children = z.array(ChildReceipt).parse(
        await execution.get("children"),
      );
      let child = children.find((c) => c.key === step.key);
      if (!child) {
        await execution.child(step.key);
        children = z.array(ChildReceipt).parse(await execution.get("children"));
        child = children.find((c) => c.key === step.key);
      }
      if (
        !child || child.kind !== step.kind || child.node_id !== step.node_id
      ) {
        throw new ExecutorError("operation.completion_unproven");
      }
      if (["queued", "running", "waiting"].includes(child.status)) {
        await execution.wait([step.key]);
        return { waiting: true };
      }
      if (child.status !== "succeeded" && !step.optional) return await finish();
    }
  }
  return await finish();
}
