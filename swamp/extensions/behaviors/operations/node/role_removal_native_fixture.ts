import { fixtureHttp } from "./role_direct_native_fixture.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const read = async () =>
  JSON.parse(await Deno.readTextFile("native-role.json"));
const save = (state: unknown) =>
  Deno.writeTextFile("native-role.json", JSON.stringify(state));
export const fixtureSourceHttp: Http = async (method, path, body) => {
  const result = await fixtureHttp(method, path, body);
  if (method !== "GET") return result;
  const data = (result.body as { data: Record<string, unknown> }).data;
  const input = data.input as Record<string, unknown>;
  const source = (input.source_removal ?? input) as Record<string, unknown>;
  return {
    ...result,
    body: { data: { ...data, input: { ...input, node_id: source.node_id } } },
  };
};
export const fixtureCleanup = async (
  raw: unknown,
  stage: string,
  _signal?: AbortSignal,
  node?: number,
) => {
  const state = await read();
  if (state.operation.kind === "node.role_relocate" && !state.target_ready) {
    throw new Error("Source cleanup outran target readiness");
  }
  const plan = raw as { node_id: number };
  state.effects.push(`source:${node ?? plan.node_id}:${stage}`);
  await save(state);
  return state.source_failure === stage
    ? { ok: false, error_code: "role.source_cleanup_failed" }
    : { ok: true };
};
export const fixtureGrace = async (seconds: number) => {
  const state = await read();
  state.effects.push(`grace:${seconds}`);
  await save(state);
};

import { Refused } from "../integrations/metrics_fleet_local.ts";
export const fixtureMetricsStage = async (phase: string) => {
  const state = await read();
  state.effects.push(`metrics:${phase}`);
  await save(state);
  if (state.metrics_failure === phase) {
    throw new Refused("metrics.fleet_failed");
  }
  return {};
};
