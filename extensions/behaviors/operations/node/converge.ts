import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { NodeExecution } from "./execution.ts";
import {
  Completion,
  ConvergeInput,
  ErrorCode,
  FootprintSnapshot,
  ResultObject,
} from "./contract.ts";

type Result = Record<string, unknown>;
type Completion = z.infer<typeof Completion>;
export type ConvergeOutcome = Completion | { waiting: true };
const Child = z.object({
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

export async function converge(
  execution: NodeExecution,
  ctx: import("../../operation-runner/mod.ts").Context,
): Promise<ConvergeOutcome> {
  let operation = execution.operation;
  ConvergeInput.parse(operation.input);
  const run = execution.run.bind(execution);
  const get = execution.get.bind(execution);
  const outcome = (result: Result): Completion => {
    const error = result.error_code === undefined
      ? null
      : ErrorCode.parse(result.error_code);
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
  const finish = async () =>
    outcome(
      await run("footprint-result", "gateway", "node.footprint-result", {}),
    );
  if (operation.cancel_requested && operation.steps.length === 0) {
    return { status: "cancelled", result: {}, error_code: null };
  }
  const prepared = await run(
    "prepare-footprint",
    "gateway",
    "node.prepare-footprint",
    ConvergeInput.parse(operation.input),
  );
  if (prepared.error_code) return outcome(prepared);
  operation = await execution.refresh();
  const snapshot = FootprintSnapshot.parse(operation.input._footprint);
  for (const artifact of snapshot.artifacts) {
    if (!artifact.required) continue;
    const step = `footprint-${artifact.name}`;
    if (Object.hasOwn(operation.step_results, step)) {
      const previous = ResultObject.parse(
        operation.step_results[step],
      );
      if (previous.error_code) {
        return previous.error_code === "operation.cancelled"
          ? outcome(previous)
          : await finish();
      }
      continue;
    }
    const childSpec = snapshot.children[artifact.name];
    if (childSpec) {
      if (artifact.name === "private-dns") {
        const listener = await run(
          "footprint-private-dns-listener",
          "agent",
          "node.install-private-dns-listener",
          snapshot.payloads["private-dns-listener"],
        );
        if (listener.error_code) {
          return listener.error_code === "operation.cancelled"
            ? outcome(listener)
            : await finish();
        }
        if (listener.installed !== true) {
          throw new ExecutorError("operation.completion_unproven");
        }
      }
      let children = z.array(Child).parse(await get("children"));
      let child = children.find((candidate) => candidate.key === step);
      if (!child) {
        const admitted = z.object({ id: z.number().int().positive() }).parse(
          await execution.child(step),
        );
        await ctx.writeResource(
          "child",
          `child-${operation.id}-${artifact.name}`,
          {
            operation_id: admitted.id,
          },
        );
        children = z.array(Child).parse(await get("children"));
        child = children.find((candidate) =>
          candidate.id === admitted.id && candidate.key === step
        );
      }
      if (
        !child || child.kind !== childSpec.kind ||
        child.node_id !== childSpec.node_id
      ) throw new ExecutorError("operation.completion_unproven");
      if (["queued", "running", "waiting"].includes(child.status)) {
        await execution.wait([step]);
        return { waiting: true };
      }
      if (child.status !== "succeeded") {
        const caddySkip = artifact.name === "caddy" &&
          child.status === "failed" &&
          ["render", "validate"].includes(String(child.result?.stage));
        const residueSkip = artifact.name === "route-residue" &&
          child.status === "failed";
        if (!caddySkip && !residueSkip) return await finish();
      }
      continue;
    }
    let result: Result;
    if (artifact.name === "agent") {
      const installed = await run(
        "footprint-agent-install",
        "node-agent",
        "agent.install",
        { pin: snapshot.agent_pin },
      );
      if (installed.error_code) {
        return installed.error_code === "operation.cancelled"
          ? outcome(installed)
          : await finish();
      }
      const configuration = await run(
        "prepare-agent-configuration",
        "gateway",
        "node.prepare-agent-configuration",
        {},
      );
      if (configuration.error_code) return outcome(configuration);
      operation = await execution.refresh();
      const configurationRun = operation.execution_context.descriptors.find((
        entry,
      ) =>
        entry.key === step && entry.transport === "agent" &&
        entry.name === "agent.configure"
      );
      if (!configurationRun) {
        throw new ExecutorError("operation.completion_unproven");
      }
      result = await run(
        step,
        "agent",
        "agent.configure",
        configurationRun.input,
      );
    } else {
      const command = ({
        "caddy-package": "caddy.install-package",
        "peer-dns": "wireguard.repair-peer-dns",
        tmpfiles: "node.publish-tmpfiles",
      } as Record<string, string>)[artifact.name];
      if (!command) {
        throw new Error(`No footprint executor for ${artifact.name}`);
      }
      result = await run(
        step,
        "agent",
        command,
        snapshot.payloads[artifact.name],
      );
    }
    if (result.error_code) {
      return result.error_code === "operation.cancelled"
        ? outcome(result)
        : await finish();
    }
  }
  return await finish();
}
