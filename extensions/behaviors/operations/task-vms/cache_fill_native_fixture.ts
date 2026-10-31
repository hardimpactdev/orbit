import type { Http } from "../../automatic-deploy/gateway.ts";
import type { CacheFill } from "./cache_host.ts";
const file = "cache-fixture.json";
const read = async () => JSON.parse(await Deno.readTextFile(file));
const save = async (state: unknown) =>
  await Deno.writeTextFile(file, JSON.stringify(state));
export const fixtureHttp: Http = async (method, path, raw) => {
  const state = await read(), body = raw as Record<string, unknown> | undefined;
  const root = method === "GET"
    ? new URL(path, "https://gateway.test").searchParams.get("run_id")
    : body?.run_id;
  if (state.root && state.root !== root) return { status: 409, body: {} };
  state.root = root;
  state.calls.push({ method, path });
  if (path.includes("private-material")) {
    await save(state);
    return {
      status: 200,
      body: { data: { github_token: Deno.env.get("ORBIT_CACHE_TEST_TOKEN") } },
    };
  }
  if (
    path.endsWith("/failure") &&
    !["succeeded", "failed", "cancelled"].includes(state.operation.status)
  ) {
    state.operation.status = "failed";
    state.operation.result = {};
    state.operation.error_code = "operation.native_failed";
  }
  if (body?.status) {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    state.posts.push({ status: body.status, result: body.result });
  }
  await save(state);
  return { status: 200, body: { data: state.operation } };
};
export async function fixtureAction(input: CacheFill) {
  const state = await read();
  state.fills++;
  await save(state);
  if (input.github_token !== Deno.env.get("ORBIT_CACHE_TEST_TOKEN")) {
    throw new Error("Private token missing");
  }
  if (state.loseReply) throw new Error(input.github_token!);
  return {
    hashes: Object.fromEntries(
      input.artifacts.map((a) => [a.key, `sha256-${"a".repeat(64)}`]),
    ),
    failures: {},
  };
}
export async function engineRefused() {
  return Boolean((await read()).engineRefused);
}
