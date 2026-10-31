import { z } from "npm:zod@4.4.3";
import { DirectArgs } from "../_lib/direct.ts";
import { Globals } from "../../operation-runner/mod.ts";
export async function event(name: string) {
  const state = JSON.parse(await Deno.readTextFile("native-removal.json"));
  state.events.push(name);
  await Deno.writeTextFile("native-removal.json", JSON.stringify(state));
  if (state.fail === name) throw new Error("route.withdrawal_failed");
  return { ready: true };
}
export async function fixtureHttp(
  method: string,
  _path: string,
  raw?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("native-removal.json"));
  if (method === "GET") return { status: 200, body: { data: state.operation } };
  const body = raw as Record<string, unknown>;
  state.operation.status = _path.endsWith("/failure") ? "failed" : body.status;
  if (body.status === "succeeded") state.events.push("record");
  await Deno.writeTextFile("native-removal.json", JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
}
export const actions = {
  certificates: () => event("certificates"),
  lan: () => event("lan"),
};
const Args = DirectArgs.extend({ target_node_id: z.number().int().positive() });
export function fixtureBehavior(type: string, methods: string[]) {
  return {
    type,
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: Object.fromEntries(
      methods.map((
        method,
      ) => [method, {
        description: method,
        arguments: Args,
        execute: () => event(method),
      }]),
    ),
  };
}

export async function fixtureReady(args: { target_node_id: number }) {
  const state = JSON.parse(await Deno.readTextFile("native-removal.json"));
  if (state.offlineNodes?.includes(args.target_node_id)) {
    throw new Error("Worker dispatch unavailable");
  }
  return { ready: true };
}
