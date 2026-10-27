import { assert } from "jsr:@std/assert@1.0.19";
import { type StageArgs } from "../../extensions/behaviors/_lib/gateway_stage.ts";
import { maintenanceResult } from "../../extensions/behaviors/_lib/swamp_maintenance.ts";
import { type WorkflowRun } from "../../extensions/behaviors/_lib/maintenance_worker.ts";
import { type startFakeGateway } from "./fake_gateway.ts";

/** Native workflow receipts at the worker boundary; the driver and factory stay real. */
export function maintenanceFixture(fake: ReturnType<typeof startFakeGateway>) {
  const started = new Set<string>();
  const calls: {
    args: StageArgs;
    group: number;
    request: Record<string, unknown>;
  }[] = [];
  const prepare = () => {
    for (const group of fake.groups.values()) group.taskable_id = group.id;
  };
  const maintenance = async (
    args: StageArgs,
    group: number,
    request: Record<string, unknown>,
  ) => {
    calls.push({
      args: structuredClone(args),
      group,
      request: structuredClone(request),
    });
    if (!started.has(args.key)) {
      started.add(args.key);
      return { state: "running" as const };
    }
    const handoff = args.handoff as {
      payload?: { commit: string; candidate: { tree: string } };
    } | undefined;
    if (request.mode === "summary" && handoff?.payload) {
      fake.groups.get(group)!.head = handoff.payload.commit;
      fake.groups.get(group)!.tree = handoff.payload.candidate.tree;
    }
    const response = await fetch(
      `${fake.url}/fixture/maintenance/${group}/${request.mode}`,
    );
    const { data } = await response.json();
    return data.lastError
      ? { state: "failed" as const, lastError: data.lastError }
      : maintenanceResult(data as WorkflowRun);
  };
  return { maintenance, calls, prepare };
}

/** Substitute only the remote transport in a disposable native-factory test repo. */
export async function installMaintenanceFixture(dir: string) {
  const module = `${dir}/extensions/behaviors/_lib/swamp_maintenance.ts`;
  const source = await Deno.readTextFile(module);
  const start = source.indexOf(
    "  let inputs = maintenanceInputs",
    source.indexOf("export async function runMaintenance("),
  );
  const end = source.indexOf("\n/** Swamp stores worker receipts", start);
  assert(start > 0 && end > start);
  await Deno.writeTextFile(
    module,
    source.slice(0, start) +
      `  const response = await http("GET", \`/fixture/maintenance/\${group}/\${request.mode}\`);
  const data = response.body!.data as Record<string, unknown>;
  if (data.lastError) return { state: "failed", lastError: String(data.lastError) };
  await recordMaintenanceExceptions(data as WorkflowRun, args.dependencyProject!.projectId, context);
  return maintenanceResult(data as WorkflowRun);
}\n` + source.slice(end),
  );
}
