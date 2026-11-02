import { z } from "npm:zod@4.4.3";
import { Result, ToolIntentInput } from "../tools/direct-contract.ts";
import { toolNode } from "../tools/node.ts";
import { localTool } from "../tools/local.ts";

const Manager = z.enum(["vp", "composer"]);
const Parent = z.object({
  operation_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  kind: z.enum([
    "node.add",
    "node.role_add",
    "node.role_converge",
    "node.role_relocate",
  ]),
  run_id: z.string().uuid(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
const ManagerInput = z.object({
  manager: Manager,
  platform: z.literal("linux"),
  architecture: ToolIntentInput.shape.architecture,
  user: ToolIntentInput.shape.user,
  materialize: ToolIntentInput.shape.materialize,
}).strict().refine((v) => v.materialize === null || v.materialize.user === v.user);
export const ParentManagerInput = z.object({
  parent: Parent,
  manager: ManagerInput,
}).strict();
export const ParentManagerOutcome = Result.extend({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  manager: Manager,
}).strict();

const resources = toolNode().resources;
export const parentManagerResources = {
  manager_before: resources.before,
  manager_outcome: resources.outcome,
};
type Context = Parameters<
  ReturnType<typeof toolNode>["methods"]["apply"]["execute"]
>[1];

/** Same-run reuse of canonical manager policy, with no Tool admission or Gateway calls. */
export function parentManagerAction(invoke = localTool) {
  const apply = toolNode(invoke).methods.apply.execute;
  return async (raw: unknown, context: Context) => {
    const parsed = ParentManagerInput.safeParse(raw);
    if (!parsed.success) throw new Error("Invalid parent manager intent");
    const { parent, manager } = parsed.data;
    const prefix =
      `manager-${parent.operation_id}-${parent.node_id}-${manager.manager}-`;
    const result = await apply({
      intent: {
        operation_id: parent.operation_id,
        node_id: parent.node_id,
        run_id: parent.run_id,
        input: ToolIntentInput.parse({
          ...manager,
          node_id: parent.node_id,
          agent_sha256: parent.agent_sha256,
        }),
      },
    }, {
      signal: context.signal,
      readResource: (name) => context.readResource(prefix + name),
      writeResource: (spec, name, value) => {
        const mapped = spec === "before"
          ? "manager_before"
          : spec === "outcome"
          ? "manager_outcome"
          : null;
        if (mapped === null) {
          throw new Error("Unknown canonical manager resource");
        }
        return context.writeResource(mapped, prefix + name, value);
      },
    });
    return {
      outcome: ParentManagerOutcome.parse({
        ...result.outcome,
        schema_version: 1,
        node_id: parent.node_id,
        manager: manager.manager,
      }),
      dataHandles: result.dataHandles,
    };
  };
}

export const convergeParentManager = parentManagerAction();
