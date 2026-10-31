import type { Http } from "../../automatic-deploy/gateway.ts";
import {
  type NodePlan,
  Refused,
  type Snapshot,
} from "../integrations/metrics_fleet_local.ts";
const read = async () =>
  JSON.parse(await Deno.readTextFile("role-metrics.json"));
const record = (value: unknown) =>
  Deno.writeTextFile("role-metrics.ndjson", JSON.stringify(value) + "\n", {
    append: true,
  });
export const fixtureHttp: Http = async (method, path) => {
  if (method !== "GET") throw new Error("Unexpected Gateway write");
  const state = await read();
  return {
    status: 200,
    body: {
      data: path.includes("private-material")
        ? {
          metrics_runtime: {
            runtime: { operation: "converge" },
            verify: { operation: "verify" },
          },
        }
        : state.operation,
    },
  };
};
export async function fixtureLocal(
  phase: string,
  node: NodePlan,
  _metrics: string,
  before?: Snapshot,
) {
  await record({ phase, node_id: node.node_id });
  const state = await read();
  if (state.failure === phase && node.node_id === 13) {
    throw new Refused("metrics.fixture_refused");
  }
  if (phase === "restore" && before?.pools === undefined) {
    throw new Error("Snapshot missing");
  }
  return phase === "snapshot"
    ? { node_exporter: {}, cadvisor: {}, service: { config: null }, pools: [] }
    : {};
}
export const fixtureRuntime = {
  credential: async () => {
    await record({ phase: "credentials" });
    return { verified: (await read()).failure !== "credentials" };
  },
  runtime: async () => {
    await record({ phase: "runtime" });
    const failure = (await read()).failure;
    if (failure === "runtime_unknown") throw new Error("Unknown commit");
    return failure === "runtime_cleanup"
      ? { error_code: "metrics.container_cleanup_failed" }
      : { changed: true };
  },
};
