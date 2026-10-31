import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import {
  runProjectStepList,
  type StepListPort,
} from "../instance-deploy/run_list.ts";
import { DestroyPath } from "./destroy_contract.ts";
import { DestroyRefusal } from "./destroy_local.ts";

const Id = z.number().int().positive();
const Step = z.object({
  name: z.string().regex(/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/),
  timeout_seconds: z.number().int().min(1).max(540),
  sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
export const DestroyTeardown = z.object({
  node_id: Id,
  project_id: Id,
  environment: z.literal("development"),
  node_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
    user !== "root"
  ),
  teardown: z.array(
    z.object({
      instance_id: Id,
      checkout: DestroyPath,
      user: z.string(),
      steps: z.array(Step).max(32),
    }).strict(),
  ).min(1).max(1024),
}).strict().refine((plan) =>
  new Set(plan.teardown.map((member) => member.instance_id)).size ===
    plan.teardown.length &&
  plan.teardown.every((member) =>
    member.user === plan.node_user &&
    new Set(member.steps.map((step) => step.name)).size === member.steps.length
  )
);
const Scripts = z.object({
  members: z.array(
    z.object({
      instance_id: Id,
      steps: z.array(
        z.object({
          name: z.string(),
          timeout_seconds: z.number().int(),
          command: z.string().max(16384),
        }).strict(),
      ).max(32),
    }).strict(),
  ).min(1).max(1024),
}).strict();
type Plan = z.infer<typeof DestroyTeardown>;
type Port = {
  read: () => Promise<{ input: unknown; cancel_requested: boolean }>;
  privateMaterial: Pick<GatewayOperation, "withPrivateMaterial">;
  run?: StepListPort["run"];
};
function stepList(plan: Plan, member: Plan["teardown"][number]) {
  return {
    instance_id: member.instance_id,
    project_id: plan.project_id,
    environment: plan.environment,
    list: "teardown" as const,
    execution: {
      variant: "checkout" as const,
      user: member.user,
      directory: member.checkout,
      seed_path: "",
      seed_commit: "",
    },
    steps: member.steps.map((step) => ({
      name: step.name,
      timeout_seconds: step.timeout_seconds,
      command_digest: step.sha256,
    })),
  };
}

/** One parent DAG step; the owning runner supervises each private app script. */
export async function runDestroyTeardown(
  raw: unknown,
  nodeId: number,
  port: Port,
  signal?: AbortSignal,
) {
  const admitted = DestroyTeardown.parse(raw);
  if (admitted.node_id !== nodeId) {
    throw new DestroyRefusal("instance.removal_conflict");
  }
  for (const member of admitted.teardown) {
    signal?.throwIfAborted();
    const result = await runProjectStepList(stepList(admitted, member), {
      read: async () => {
        const current = await port.read();
        const plan = DestroyTeardown.parse(current.input);
        if (
          plan.node_id !== nodeId || plan.project_id !== admitted.project_id
        ) {
          throw new DestroyRefusal("instance.step_not_admitted");
        }
        const matches = plan.teardown.filter((row) =>
          row.instance_id === member.instance_id
        );
        if (matches.length !== 1) {
          throw new DestroyRefusal("instance.step_not_admitted");
        }
        return {
          input: stepList(plan, matches[0]),
          cancel_requested: current.cancel_requested,
        };
      },
      program: (step, execute) =>
        port.privateMaterial.withPrivateMaterial(async (material) => {
          const scripts = Scripts.parse(material.teardown);
          const matches = scripts.members.filter((row) =>
            row.instance_id === member.instance_id
          )
            .flatMap((row) => row.steps).filter((row) =>
              row.name === step.name &&
              row.timeout_seconds === step.timeout_seconds
            );
          if (matches.length !== 1) {
            throw new Error("instance.step_not_admitted");
          }
          await execute(matches[0].command);
        }),
      ...(port.run === undefined ? {} : { run: port.run }),
    }, signal);
    if ("cancelled" in result) throw new DestroyRefusal("operation.cancelled");
    if (result.outcome !== "succeeded") {
      throw new DestroyRefusal(result.error_code);
    }
  }
  return {
    teardown_complete: true,
    instance_ids: admitted.teardown.map((row) => row.instance_id),
  };
}
