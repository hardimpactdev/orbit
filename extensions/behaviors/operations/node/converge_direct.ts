import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import {
  Args,
  Code,
  Outcome,
  Snapshot,
  Stage,
} from "./converge_direct_contract.ts";
import { convergeFootprintStage } from "./converge_footprint.ts";

const Admission = z.object({
  ready: z.boolean(),
  started_at: z.string(),
  snapshot: Snapshot.nullable(),
  status: z.enum(["running", "succeeded", "failed", "cancelled"]),
}).strict();
const Current = z.object({
  id: z.number().int().positive(),
  kind: z.literal("node.converge"),
  node_id: z.number().int().positive(),
  status: z.enum(["queued", "running", "succeeded", "failed", "cancelled"]),
  input: z.unknown(),
  cancel_requested: z.boolean(),
});
const Cancellation = z.object({ cancel_requested: z.boolean() }).strict();
const ObserveArgs = Args.extend({ stage: Stage });
const StepArgs = Args.extend({
  admission: Admission,
  stage: Stage,
  cancellation: Cancellation,
  binary_changed: z.boolean().default(false),
});
const ProjectArgs = Args.extend({
  admission: Admission,
  outcomes: z.array(Outcome).max(9),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
export type StageAction = (
  stage: Stage,
  snapshot: Snapshot,
  gateway: GatewayOperation,
  binaryChanged: boolean,
  signal?: AbortSignal,
) => Promise<Outcome>;

/** One root; native jobs, assertions and outputs own ordering and recovery. */
export function nodeConverge(
  action: StageAction = convergeFootprintStage,
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
) {
  return {
    type: "@nckrtl/node-converge-direct",
    version: "2026.10.30.1",
    globalArguments: Globals,
    resources: {
      cancellation: {
        description: "Current cancellation observed before this native stage",
        schema: Cancellation,
        lifetime: "1d",
        garbageCollection: 10,
      },
      admission: {
        description: "Safe immutable admitted footprint for this native run",
        schema: Admission,
        lifetime: "1d",
        garbageCollection: 10,
      },
      outcome: {
        description:
          "Bounded local stage result; uncertainty produces no outcome",
        schema: Outcome,
        lifetime: "1d",
        garbageCollection: 10,
      },
    },
    methods: {
      admit: {
        description:
          "Read the bound intent once and project running before Node work",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          const current = Current.parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id || current.node_id !== args.node_id
          ) {
            throw new Error("Converge intent differs from the native root");
          }
          const started_at = new Date().toISOString();
          let admission: z.infer<typeof Admission>;
          if (["succeeded", "failed", "cancelled"].includes(current.status)) {
            admission = Admission.parse({
              ready: false,
              started_at,
              snapshot: null,
              status: current.status,
            });
          } else if (current.cancel_requested || ctx.signal?.aborted) {
            await gateway.project({
              status: "cancelled",
              started_at,
              finished_at: started_at,
              result: {},
              error_code: null,
            });
            admission = {
              ready: false,
              started_at,
              snapshot: null,
              status: "cancelled",
            };
          } else {
            const snapshot = Snapshot.parse(current.input);
            if (snapshot.node_id !== args.node_id) {
              throw new Error("Footprint belongs to another Node");
            }
            await gateway.project({ status: "running", started_at });
            admission = {
              ready: true,
              started_at,
              snapshot,
              status: "running",
            };
          }
          await ctx.writeResource("admission", "admission", admission);
          return { ready: admission.ready };
        },
      },
      observe: {
        description:
          "Observe cancellation before a new native effect checkpoint",
        arguments: ObserveArgs,
        execute: async (raw: z.infer<typeof ObserveArgs>, ctx: Context) => {
          const args = ObserveArgs.parse(raw);
          const gateway = new GatewayOperation(
            makeHttp(ctx),
            Args.parse({
              operation_id: args.operation_id,
              kind: args.kind,
              node_id: args.node_id,
              run_id: args.run_id,
            }),
          );
          const current = Current.parse(await gateway.read(ctx.signal));
          if (
            current.id !== args.operation_id ||
            current.node_id !== args.node_id ||
            ["succeeded", "failed"].includes(current.status)
          ) {
            throw new Error(
              "Cancellation observation has no open admitted Node intent",
            );
          }
          const cancellation = {
            cancel_requested: current.cancel_requested ||
              current.status === "cancelled" || ctx.signal?.aborted === true,
          };
          await ctx.writeResource(
            "cancellation",
            args.stage + "-cancellation",
            cancellation,
          );
          return cancellation;
        },
      },
      stage: {
        description:
          "Apply one fixed footprint stage locally on the admitted Node",
        arguments: StepArgs,
        execute: async (raw: z.infer<typeof StepArgs>, ctx: Context) => {
          const args = StepArgs.parse(raw);
          const snapshot = Snapshot.parse(args.admission.snapshot);
          if (!args.admission.ready || snapshot.node_id !== args.node_id) {
            throw new Error("Stage has no admitted Node");
          }
          const gateway = new GatewayOperation(
            makeHttp(ctx),
            Args.parse({
              operation_id: args.operation_id,
              kind: args.kind,
              node_id: args.node_id,
              run_id: args.run_id,
            }),
          );
          // Native resume reuses the observation completed before the first dispatch.
          // An uncertain write must reconcile before any later cancellation boundary.
          const cancelled = args.cancellation.cancel_requested;
          const outcome = Outcome.parse(
            cancelled
              ? {
                stage: args.stage,
                ok: false,
                error_code: "operation.cancelled",
              }
              : snapshot.refusal
              ? { stage: args.stage, ok: false, error_code: snapshot.refusal }
              : await action(
                args.stage,
                snapshot,
                gateway,
                args.binary_changed,
                ctx.signal,
              ),
          );
          if (
            outcome.stage !== args.stage ||
            outcome.entry &&
              !snapshot.artifacts.some((a) => a.name === outcome.entry!.name)
          ) {
            throw new Error("Stage result differs from its admitted footprint");
          }
          await ctx.writeResource("outcome", args.stage, outcome);
          return { ok: outcome.ok };
        },
      },
      project: {
        description:
          "Project only proven bounded native results; leave uncertain effects unresolved",
        arguments: ProjectArgs,
        execute: async (raw: z.infer<typeof ProjectArgs>, ctx: Context) => {
          const args = ProjectArgs.parse(raw);
          if (!args.admission.ready) {
            if (args.admission.status === "failed") {
              throw new Error("node.footprint_failed");
            }
            return { status: args.admission.status };
          }
          const snapshot = Snapshot.parse(args.admission.snapshot);
          if (
            snapshot.node_id !== args.node_id ||
            new Set(args.outcomes.map((o) => o.stage)).size !==
              args.outcomes.length
          ) {
            throw new Error("Projection differs from the admitted native DAG");
          }
          const failure = args.outcomes.find((o) => !o.ok);
          if (!failure && args.outcomes.length !== Stage.options.length) {
            throw new Error("A native stage outcome is uncertain");
          }
          if (failure?.error_code === "operation.cancelled") {
            const gateway = new GatewayOperation(
              makeHttp(ctx),
              Args.parse({
                operation_id: args.operation_id,
                kind: args.kind,
                node_id: args.node_id,
                run_id: args.run_id,
              }),
            );
            await gateway.project({
              status: "cancelled",
              started_at: args.admission.started_at,
              finished_at: new Date().toISOString(),
              result: {},
              error_code: null,
            });
            return { status: "cancelled" };
          }
          const artifacts = args.outcomes.flatMap((o) =>
            o.entry ? [o.entry] : []
          );
          const names = new Set(artifacts.map((a) => a.name));
          if (names.size !== artifacts.length) {
            throw new Error("Artifact outcomes are duplicated");
          }
          const code = failure?.error_code ??
            args.outcomes.find((o) => o.deferred_error)?.deferred_error;
          if (!code && snapshot.artifacts.some((a) => !names.has(a.name))) {
            throw new Error("Footprint completion is unproven");
          }
          const result = {
            artifacts,
            footprint_proven: !code,
            ...(code ? { error_code: Code.parse(code) } : {}),
            ...(failure?.failed_artifact
              ? { failed_artifact: failure.failed_artifact }
              : {}),
          };
          const status = code ? "failed" : "succeeded";
          const gateway = new GatewayOperation(
            makeHttp(ctx),
            Args.parse({
              operation_id: args.operation_id,
              kind: args.kind,
              node_id: args.node_id,
              run_id: args.run_id,
            }),
          );
          await gateway.project({
            status,
            started_at: args.admission.started_at,
            finished_at: new Date().toISOString(),
            result,
            error_code: code ?? null,
          });
          if (code) throw new Error(code);
          return { status };
        },
      },
    },
  };
}
export const behavior = nodeConverge();
