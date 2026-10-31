import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import {
  readRuntime,
  runtimeReadProgram,
} from "../../observation/doctor_native_runtime.ts";
import { FirewallProgram } from "../routes/firewall-program.ts";
import { type NodeCommand, run } from "../_lib/node-commands.ts";

const ResultLimit = 256 * 1024;
const RowLimit = 512;
const Args = DirectArgs.extend({
  kind: z.literal("firewall.inspect"),
  node_id: z.number().int().positive(),
});
const Shape = z.object({
  comment: z.string(),
  action: z.enum(["allow", "deny"]),
  direction: z.enum(["in", "out", "fwd"]),
  source: z.string(),
  destination: z.string(),
  port: z.string(),
  protocol: z.enum(["any", "tcp", "udp"]),
  in_interface: z.string().nullable(),
  out_interface: z.string().nullable(),
  family: z.literal("v4"),
}).strict();
const Snapshot = z.object({
  backend: z.enum(["active", "inactive", "absent"]),
  live: z.array(Shape).max(RowLimit),
  observed_at: z.iso.datetime(),
}).strict();
const Refusal = z.object({
  error_code: z.enum([
    "firewall.inspection_too_large",
    "firewall.inspection_unavailable",
  ]),
}).strict();
const Outcome = z.union([Snapshot, Refusal]);
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Local = (argv: string[], signal?: AbortSignal) => Promise<string>;
class InspectionTooLarge extends Error {
  constructor() {
    super("firewall.inspection_too_large");
  }
}

/** Reuse the exact writer lock and the existing observation parser in one process. */
export const LockedInspectionProgram = `
import json,sys
lock_scope={'__name__':'orbit_firewall_lock'}
runtime_scope={'__name__':'orbit_firewall_observation'}
exec(${JSON.stringify(FirewallProgram)},lock_scope)
exec(${JSON.stringify(runtimeReadProgram)},runtime_scope)
try:
 payload=json.loads(sys.argv[1])
 if payload['name']!='firewall.inspect' or payload['input']!={'expected':[],'exclusive_families':[],'stored':False}:raise ValueError()
 with lock_scope['transaction_lock']():
  snapshot=runtime_scope['runtime_read'](payload['name'],payload['input'])
  output=json.dumps(snapshot,separators=(',',':'))
  if len(snapshot['live'])>${RowLimit} or len(output.encode())>=${ResultLimit}:output='{"error_code":"firewall.inspection_too_large"}'
 print(output)
except lock_scope['Refusal']:
 print('{"error_code":"firewall.inspection_unavailable"}')
except Exception:
 print('{"error_code":"firewall.inspection_unavailable"}')
`;

/** Only the canonical fixed readRuntime invocation is accepted by this local runner. */
export async function lockedFirewallLocal(
  argv: string[],
  signal?: AbortSignal,
  command: NodeCommand = run,
): Promise<string> {
  if (
    argv.length !== 7 ||
    argv.slice(0, 5).join("\0") !==
      ["/usr/bin/sudo", "-n", "--", "/usr/bin/python3", "-c"].join("\0") ||
    argv[5] !== runtimeReadProgram
  ) throw new Error("firewall.inspection_unavailable");
  signal?.throwIfAborted();
  const result = await command(
    ["/usr/bin/python3", "-I", "-c", LockedInspectionProgram, argv[6]],
    undefined,
    "root",
    { signal, deadlineMs: 30_000, maxOutputBytes: ResultLimit },
  );
  if (result.stdout.length >= ResultLimit) throw new InspectionTooLarge();
  if (!result.success) throw new Error("firewall.inspection_unavailable");
  return new TextDecoder().decode(result.stdout);
}

/** Complete IPv4 snapshot only; oversized observations fail without truncation. */
export async function inspectFirewall(
  local: Local = lockedFirewallLocal,
  signal?: AbortSignal,
) {
  try {
    const raw = await readRuntime("firewall.inspect", {
      expected: [],
      exclusive_families: [],
      stored: false,
    }, (argv) => local(argv, signal));
    if (!raw) throw new Error("firewall.inspection_unavailable");
    if (Array.isArray(raw.live) && raw.live.length > RowLimit) {
      return { error_code: "firewall.inspection_too_large" as const };
    }
    const snapshot = Snapshot.parse({
      backend: raw.backend,
      live: raw.live,
      observed_at: new Date().toISOString(),
    });
    if (
      new TextEncoder().encode(JSON.stringify(snapshot)).length >= ResultLimit
    ) {
      return { error_code: "firewall.inspection_too_large" as const };
    }
    return snapshot;
  } catch (error) {
    if (signal?.aborted) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    if (
      error instanceof InspectionTooLarge ||
      (typeof error === "object" && error !== null && "error_code" in error &&
        error.error_code === "firewall.inspection_too_large")
    ) return { error_code: "firewall.inspection_too_large" as const };
    return { error_code: "firewall.inspection_unavailable" as const };
  }
}

export function firewallInspection(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  local: Local = lockedFirewallLocal,
) {
  return {
    type: "@nckrtl/firewall-inspection",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      inspect: {
        description:
          "Inspect the admitted Node's live firewall under its writer lock",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) =>
          await directOperation(
            args,
            new GatewayOperation(makeHttp(ctx), args),
            (raw) => {
              const input = z.object({ node_id: z.literal(args.node_id) })
                .strict().parse(raw);
              if (input.node_id !== args.node_id) {
                throw new Error("firewall.inspection_unavailable");
              }
              return inspectFirewall(local, ctx.signal);
            },
            (result) => Outcome.parse(result),
            ctx.signal,
          ),
      },
    },
  };
}
export const behavior = firewallInspection();
