import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../operations/_lib/direct.ts";
import { type NativeReadCall, readDoctor } from "./doctor_native.ts";
import { Globals } from "./contract.ts";
import {
  Families,
  type FamilyReport,
  issue,
  NodeInput,
  type NodeIntent,
  type ProbeContext,
  report,
  type Row,
} from "./doctor_contract.ts";
import { checkNode } from "./doctor_node.ts";
import { mergeReports } from "./doctor_merge.ts";
import { checkRuntime } from "./doctor_runtime.ts";
import { checkApplications } from "./doctor_applications.ts";
import { checkInfrastructure } from "./doctor_infrastructure.ts";
import { collectLogs } from "./doctor_logs.ts";

const Args = DirectArgs.extend({
  kind: z.literal("doctor.inspect"),
  node_id: z.number().int().positive(),
});
const Input = z.object({
  nodes: z.array(NodeInput),
  families: z.array(z.enum(Families)),
  collect_problems: z.boolean(),
  logs: z.record(z.string(), z.unknown()).optional(),
});
const Outcome = z.object({
  id: z.number(),
  kind: z.literal("doctor.inspect"),
  node_id: z.number(),
  status: z.string(),
  input: Input,
  cancel_requested: z.boolean(),
});
const Issue = z.object({
  code: z.string().regex(/^[a-z0-9_.-]+$/),
  kind: z.enum(["drift", "unverifiable", "informational"]),
  resource_type: z.enum(Families),
  resource_id: z.union([z.number().int(), z.string().max(255)]).nullable(),
  resource_name: z.string().max(255).nullable(),
  summary: z.string().max(1000),
  expected: z.union([z.boolean(), z.string().max(255)]).nullable(),
  observed: z.union([z.boolean(), z.string().max(255)]).nullable(),
});
const FamilyOutput = z.object({
  family: z.enum(Families),
  status: z.enum(["healthy", "drift", "unverifiable"]),
  checked: z.number().int().nonnegative(),
  issues: z.array(Issue),
  observed_resources: z.array(z.union([z.number().int(), z.string()])),
  observed_checks: z.record(z.string(), z.array(z.string())),
  observed_inventories: z.record(
    z.string(),
    z.array(z.union([z.number().int(), z.string()])),
  ),
});
const NodeOutput = z.object({
  node_id: z.number().int().positive(),
  node_name: z.string(),
  healthy: z.boolean(),
  families: z.array(FamilyOutput),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type NativeArgs = z.infer<typeof Args>;

function admitted(raw: unknown, args: NativeArgs) {
  const current = Outcome.parse(raw);
  if (current.id !== args.operation_id || current.node_id !== args.node_id) {
    throw new Error("doctor.intent_mismatch");
  }
  return current;
}
function nodeReport(node: NodeIntent, families: FamilyReport[]) {
  return NodeOutput.parse({
    node_id: node.id,
    node_name: node.name,
    healthy: families.every((f) => f.status === "healthy"),
    families,
  });
}
function bounded(reports: FamilyReport[]): FamilyReport[] {
  return reports.map((f) => ({
    ...f,
    issues: f.issues.map((i) => ({
      ...i,
      summary: i.summary.slice(0, 1000),
      expected: typeof i.expected === "string"
        ? i.expected.slice(0, 255)
        : i.expected,
      observed: typeof i.observed === "string"
        ? i.observed.slice(0, 255)
        : i.observed,
      resource_name: i.resource_name?.slice(0, 255) ?? null,
    })),
  }));
}
export function summarize(nodes: z.infer<typeof NodeOutput>[]) {
  const families = nodes.flatMap((n) => n.families),
    issues = families.flatMap((f) => f.issues);
  return {
    healthy: nodes.every((n) => n.healthy),
    nodes,
    summary: {
      nodes: nodes.length,
      families: families.length,
      checks: families.reduce((n, f) => n + f.checked, 0),
      drift: issues.filter((i) => i.kind === "drift").length,
      unverifiable: issues.filter((i) => i.kind === "unverifiable").length,
      informational: issues.filter((i) => i.kind === "informational").length,
    },
  };
}
export async function localRead(argv: string[]): Promise<string> {
  const result = await new Deno.Command(argv[0], {
    args: argv.slice(1),
    stdin: "null",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(60_000),
  }).output();
  if (!result.success || result.stdout.length > 8_388_608) {
    throw new Error("doctor.local_read_failed");
  }
  return new TextDecoder().decode(result.stdout);
}

/** One native DAG. Every inspection is a read on its exact admitted Node. */
export function doctor(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  run: (call: NativeReadCall, signal?: AbortSignal) => Promise<Row> = (
    call,
    signal,
  ) => readDoctor(call, local, signal),
  local = localRead,
  logs = collectLogs,
) {
  const connection = (args: NativeArgs, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  return {
    type: "@nckrtl/doctor",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      prepare: {
        description: "Read bound Doctor intent and select placed observations",
        arguments: Args,
        execute: async (args: NativeArgs, ctx: Context) => {
          const gateway = connection(args, ctx),
            current = admitted(await gateway.read(ctx.signal), args);
          if (
            ["succeeded", "failed", "cancelled"].includes(current.status) ||
            current.cancel_requested
          ) return { node_ids: [] };
          await gateway.project({
            status: "running",
            started_at: new Date().toISOString(),
          });
          return {
            node_ids: current.input.nodes.filter((n) => n.managed && !n.parked)
              .map((n) => n.id),
          };
        },
      },
      inspect: {
        description:
          "Compare one Node's local observations with admitted expectations",
        arguments: Args.extend({ target_node_id: z.number().int().positive() }),
        execute: async (
          args: NativeArgs & { target_node_id: number },
          ctx: Context,
        ) => {
          const gateway = connection(args, ctx),
            current = admitted(await gateway.read(ctx.signal), args),
            input = current.input;
          const node = input.nodes.find((n) => n.id === args.target_node_id) as
            | NodeIntent
            | undefined;
          if (!node || !node.managed || node.parked) {
            throw new Error(
              "doctor.node_not_admitted",
            );
          }
          if (current.cancel_requested || ctx.signal?.aborted) {
            return {
              cancelled: true,
            };
          }
          const invoke = async (name: string, body: Row, _sudo = true) => {
            const result = await run({ name, input: body, node }, ctx.signal);
            if (result.error_code) throw new Error("doctor.observation_failed");
            return result;
          };
          let observation: Row = {}, inspectionFailed = false;
          try {
            observation = await invoke("doctor.inspect-node", {
              user: node.user,
              address: node.address,
            });
          } catch {
            // An unavailable Node identity read must not prevent independent domain reads.
            observation = { reachable: true, node_read_unavailable: true };
          }
          const encoded = JSON.stringify(node.resources);
          if (
            encoded.includes("/__orbit_doctor_home__") ||
            encoded.includes("orbit-doctor-group")
          ) {
            try {
              const account = await invoke("account.lookup", {
                user: node.user,
              });
              if (
                account.user !== node.user ||
                typeof account.home !== "string" ||
                !account.home.startsWith("/") ||
                typeof account.group !== "string" ||
                !/^[a-z_][a-z0-9_-]{0,31}$/.test(account.group)
              ) throw new Error("doctor.account_invalid");
              const replace = (value: unknown): unknown =>
                typeof value === "string"
                  ? value.replaceAll(
                    "/__orbit_doctor_home__",
                    account.home as string,
                  ).replaceAll("orbit-doctor-group", account.group as string)
                  : Array.isArray(value)
                  ? value.map(replace)
                  : value && typeof value === "object"
                  ? Object.fromEntries(
                    Object.entries(value).map((
                      [key, item],
                    ) => [key, replace(item)]),
                  )
                  : value;
              node.resources = replace(
                node.resources,
              ) as NodeIntent["resources"];
            } catch {
              inspectionFailed = true;
            }
          }
          const probe: ProbeContext = {
            node,
            nodes: input.nodes as NodeIntent[],
            observation,
            inspectionFailed,
            run: invoke,
            local,
            privateMaterial: {},
            signal: ctx.signal,
          };
          const reports: FamilyReport[] = [];
          const inspect = async (material: Row) => {
            probe.privateMaterial = material;
            const schedules = material.schedules as Row | undefined;
            for (const row of node.resources.schedules ?? []) {
              const command = schedules?.[String(row.id)];
              if (typeof command === "string") {
                row.input = { ...(row.input as Row), command };
              }
            }
            if (input.families.includes("node")) {
              reports.push(await checkNode(probe));
            }
            reports.push(...await checkRuntime(probe, input.families));
            reports.push(...await checkApplications(probe, input.families));
            reports.push(...await checkInfrastructure(probe, input.families));
            probe.privateMaterial = {};
          };
          if (
            (node.resources.database_servers ?? []).length ||
            (node.resources.schedules ?? []).length
          ) await gateway.withPrivateMaterial(inspect);
          else await inspect({});
          const ordered = input.families.map((f) =>
            reports.find((r) => r.family === f) ?? report(f, 0, [])
          );
          return nodeReport(node, bounded(ordered));
        },
      },
      finish: {
        description: "Combine placed reports and record the Doctor result",
        arguments: Args.extend({
          node_results: z.record(z.string(), z.unknown()).default({}),
        }),
        execute: async (
          args: NativeArgs & { node_results: Row },
          ctx: Context,
        ) => {
          const gateway = connection(args, ctx);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = Input.parse(raw), nodes = [];
              for (const node of input.nodes as NodeIntent[]) {
                const step = args.node_results[`inspect-${node.id}`] as {
                  outputs?: unknown;
                } | undefined;
                const parsed = NodeOutput.safeParse(step?.outputs);
                if (parsed.success && parsed.data.node_id === node.id) {
                  nodes.push(parsed.data);
                  continue;
                }
                if (node.parked) {
                  nodes.push(nodeReport(
                    node,
                    input.families.map((f) =>
                      report(
                        f,
                        f === "node" ? 1 : 0,
                        f === "node"
                          ? [
                            issue(
                              "node",
                              "node.task_vm_parked",
                              node,
                              "The Node is a parked task VM.",
                              null,
                              "parked",
                              "informational",
                            ),
                          ]
                          : [],
                      )
                    ),
                  ));
                  continue;
                }
                const families: FamilyReport[] = [];
                if (!node.managed) {
                  const probe: ProbeContext = {
                    node,
                    nodes: input.nodes as NodeIntent[],
                    observation: {},
                    inspectionFailed: false,
                    run: () =>
                      Promise.reject(new Error("doctor.unmanaged_node")),
                    local,
                    privateMaterial: {},
                  };
                  for (const f of input.families) {
                    families.push(
                      f === "node" ? await checkNode(probe) : report(f, 0, []),
                    );
                  }
                } else {for (const f of input.families) {
                    families.push(report(f, 0, [issue(
                      f,
                      f === "node"
                        ? "node.inspection_failed"
                        : `${f}.node_unreachable`,
                      f === "node" ? node : `node:${node.id}`,
                      "The Node worker did not return an observation.",
                      "reachable",
                      "unverifiable",
                      "unverifiable",
                    )]));
                  }}
                nodes.push(nodeReport(node, bounded(families)));
              }
              const result: Row = {
                report: summarize(
                  mergeReports(input.nodes as NodeIntent[], nodes),
                ),
              };
              if (input.collect_problems && input.logs) {
                result.logs = await logs(input.logs);
              }
              return result;
            },
            (result) => result,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = { ...doctor(), type: "@nckrtl/doctor" };
