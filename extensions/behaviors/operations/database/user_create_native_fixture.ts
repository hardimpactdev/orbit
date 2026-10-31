import type { Http } from "../../automatic-deploy/gateway.ts";
import type { NodeCall } from "../_lib/node-agent.ts";

const file = "private-user-fixture.json";
async function state() {
  return JSON.parse(await Deno.readTextFile(file));
}
export const fixtureHttp: Http = async (method, path, body) => {
  const value = await state();
  value.calls.push(path);
  await Deno.writeTextFile(file, JSON.stringify(value));
  if (path.includes("/private-material?")) {
    return { status: 200, body: { data: value.material } };
  }
  if (method === "GET") {
    return { status: 200, body: { data: value.operation } };
  }
  const projection = body as Record<string, unknown>;
  if (value.loseProjection && projection.status === "succeeded") {
    value.loseProjection = false;
    await Deno.writeTextFile(file, JSON.stringify(value));
    throw new Error("Lost terminal projection before commit");
  }
  value.operation.status = projection.status;
  value.operation.result = projection.result ?? null;
  await Deno.writeTextFile(file, JSON.stringify(value));
  return { status: 200, body: { data: value.operation } };
};

/** Fixture only: native history sees no real Gateway or sudo invocation. */
export async function fixtureInvoke(call: NodeCall) {
  const value = await state();
  if (
    call.name !== "database.server-admin" ||
    call.input.password !== value.material.password ||
    call.input.sql !== value.material.sql
  ) throw new Error("Wrong fixed agent input");
  value.effects++;
  await Deno.writeTextFile(file, JSON.stringify(value));
  if (value.failEffect) throw new Error(value.material.sql);
  return { output: value.material.sql };
}
