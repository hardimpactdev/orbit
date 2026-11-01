import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
type Context = {
  readResource: (name: string) => Promise<unknown>;
  globalArgs: { gatewayUrl: string; caFile: string };
  signal?: AbortSignal;
  writeResource: (
    schema: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import { localProcessAction } from "./native-action.ts";
import { startActions, StartInput } from "./start.ts";

export const InstanceKind = z.enum([
  "process.restart_instance",
]);
const Args = DirectArgs.extend({
  kind: InstanceKind,
  node_id: z.number().int().positive(),
});
const Id = z.union([z.number().int().positive(), z.string().uuid()]);
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const State = z.string().regex(/^[a-z][a-z_-]{0,63}$/);
const MemberResult = z.union([
  z.object({ id: Id, ready: z.literal(true), state: State.optional() })
    .strict(),
  z.object({ id: Id, error_code: Code }).strict(),
]);
const Plan = z.object({
  member_ids: z.array(Id).max(4096),
  proceed: z.boolean(),
  vite: z.boolean(),
  error_code: Code.optional(),
}).strict();
export const instanceResources = {
  instancePlan: {
    description: "Public same-root Instance member selection",
    schema: Plan,
    lifetime: "1d",
    garbageCollection: 1,
  },
  instanceMember: {
    description: "Bounded native Instance member result",
    schema: MemberResult,
    lifetime: "1d",
    garbageCollection: 1,
  },
};
export const InstanceManifest = z.object({
  instance_id: z.number().int().positive(),
  members: z.array(
    z.object({ id: Id, input: z.record(z.string(), z.unknown()) }).strict(),
  ).max(4096),
  caddy_publication: z.unknown().optional(),
}).strict().superRefine((input, ctx) => {
  if (new Set(input.members.map((m) => m.id)).size !== input.members.length) {
    ctx.addIssue({ code: "custom", message: "Duplicate Instance member" });
  }
});
const Parent = z.object({
  id: z.number().int().positive(),
  kind: InstanceKind,
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  cancel_requested: z.boolean(),
  input: InstanceManifest,
});
const planName = (run: string) => "instance-plan-" + run;
const memberName = (run: string, id: number | string) =>
  "instance-member-" + run + "-" + id;

/** Pure placed actions for an owning native parent. No Gateway read or projection. */
export function instanceActions(
  invoke: typeof localProcessAction = localProcessAction,
  traffic = startActions(invoke),
) {
  return {
    prepare: async (
      members: z.infer<typeof InstanceManifest>["members"],
      signal?: AbortSignal,
    ) => {
      let vite = false;
      for (const member of members) {
        const control = StartInput.parse(member.input);
        vite ||= control.vite_plan !== undefined;
        const result = await traffic.prepare(
          control,
          signal,
          "process.restart",
        );
        if ("error_code" in result) {
          return {
            vite,
            proceed: false,
            error_code: result.error_code,
          };
        }
      }
      return { vite, proceed: true };
    },
    apply: async (
      member: z.infer<typeof InstanceManifest>["members"][number],
      publicationStatus: "succeeded" | "failed" | "skipped",
      signal?: AbortSignal,
    ): Promise<z.infer<typeof MemberResult>> => {
      const control = StartInput.parse(member.input);
      if (
        control.vite_plan !== undefined &&
        publicationStatus !== "succeeded"
      ) {
        return { id: member.id, error_code: "process.caddy_failed" };
      }
      const result = await traffic.activate(
        control,
        signal,
        "process.restart",
      );
      return MemberResult.parse({ id: member.id, ...result });
    },
  };
}

/** One model lock, one root, placed member steps. No member Operation or result request. */
export function instanceMethods(
  makeHttp: (ctx: Context) => Http,
  invoke: typeof localProcessAction = localProcessAction,
  traffic = startActions(invoke),
) {
  const actions = instanceActions(invoke, traffic);
  const read = async (args: z.infer<typeof Args>, ctx: Context) => {
    const gateway = new GatewayOperation(makeHttp(ctx), args);
    const parent = Parent.parse(await gateway.read(ctx.signal));
    if (
      parent.id !== args.operation_id || parent.kind !== args.kind ||
      parent.node_id !== args.node_id
    ) throw new Error("Instance runtime parent differs");
    for (const member of parent.input.members) {
      const input = StartInput.parse(member.input);
      if (input.process_id !== member.id) {
        throw new Error("Process member differs");
      }
    }
    return { gateway, parent };
  };
  return {
    prepareInstance: {
      description:
        "Read the immutable Instance plan and prepare native traffic",
      arguments: Args,
      execute: async (args: z.infer<typeof Args>, ctx: Context) => {
        const { parent } = await read(args, ctx);
        const plan: z.infer<typeof Plan> = {
          member_ids: parent.input.members.map((m) => m.id),
          proceed: ["queued", "running"].includes(parent.status) &&
            !parent.cancel_requested && !ctx.signal?.aborted,
          vite: false,
        };
        if (plan.proceed) {
          Object.assign(
            plan,
            await actions.prepare(parent.input.members, ctx.signal),
          );
        }
        const handle = await ctx.writeResource(
          "instancePlan",
          planName(args.run_id),
          plan,
        );
        return { dataHandles: [handle], ...plan };
      },
    },
    applyInstanceMember: {
      description:
        "Apply one recorded member inside its existing Instance root",
      arguments: Args.extend({
        member_id: Id,
        publication_status: z.enum(["succeeded", "failed", "skipped"]),
      }),
      execute: async (
        args: z.infer<typeof Args> & {
          member_id: number | string;
          publication_status: "succeeded" | "failed" | "skipped";
        },
        ctx: Context,
      ) => {
        const { parent } = await read(args, ctx);
        if (
          ["succeeded", "failed", "cancelled"].includes(parent.status)
        ) {
          return { status: parent.status };
        }
        const member = parent.input.members.find((m) =>
          m.id === args.member_id
        );
        if (!member) throw new Error("Unknown Instance member");
        const plan = Plan.parse(
          await ctx.readResource(planName(args.run_id)),
        );
        if (
          !plan.proceed || parent.cancel_requested ||
          ctx.signal?.aborted
        ) {
          throw new Error("Instance runtime is not prepared");
        }
        let result: z.infer<typeof MemberResult>;
        if (plan.vite && args.publication_status !== "succeeded") {
          result = {
            id: member.id,
            error_code: "process.caddy_failed",
          };
        } else {
          result = await actions.apply(
            member,
            args.publication_status,
            ctx.signal,
          );
        }
        const handle = await ctx.writeResource(
          "instanceMember",
          memberName(args.run_id, member.id),
          result,
        );
        return { dataHandles: [handle] };
      },
    },
    finishInstance: {
      description: "Project only the native Instance member outcomes",
      arguments: Args,
      execute: async (args: z.infer<typeof Args>, ctx: Context) => {
        const { gateway } = await read(args, ctx);
        return await directOperation(args, gateway, async (safe) => {
          const input = InstanceManifest.parse(safe);
          const plan = Plan.parse(
            await ctx.readResource(planName(args.run_id)),
          );
          const members: z.infer<typeof MemberResult>[] = [];
          let code = plan.error_code;
          for (const member of input.members) {
            const saved = await ctx.readResource(
              memberName(args.run_id, member.id),
            );
            if (!saved) {
              code ??= "operation.runtime_incomplete";
              continue;
            }
            const outcome = MemberResult.parse(saved);
            if (outcome.id !== member.id) {
              throw new Error("Instance result member differs");
            }
            members.push(outcome);
            if ("error_code" in outcome) {
              code ??= outcome.error_code;
            }
          }
          return code ? { error_code: code, members } : {
            ready: true,
            members,
            ...(args.kind === "process.restart_instance"
              ? { process_ids: members.map((m) => m.id) }
              : {}),
          };
        }, (result) =>
          z.union([
            z.object({
              ready: z.literal(true),
              members: z.array(MemberResult).max(4096),
              process_ids: z.array(z.number().int().positive())
                .max(4096)
                .optional(),
            }).strict(),
            z.object({
              error_code: Code,
              members: z.array(MemberResult).max(4096),
            }).strict(),
          ]).parse(result), ctx.signal);
      },
    },
  };
}

/** Dedicated native parent behavior; no member Operation is admitted. */
export function instanceRuntime(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: typeof localProcessAction = localProcessAction,
) {
  return {
    type: "@nckrtl/instance-runtime",
    version: "2026.11.01.2",
    globalArguments: Globals,
    resources: instanceResources,
    methods: instanceMethods(makeHttp, invoke, startActions(invoke)),
  };
}
export const behavior = instanceRuntime();
