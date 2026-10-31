import type { Http } from "../../automatic-deploy/gateway.ts";
import { nativeResume } from "./resume_direct.ts";

const file = "resume-fixture.json";
const read = async () => JSON.parse(await Deno.readTextFile(file));
const save = async (state: unknown) =>
  await Deno.writeTextFile(file, JSON.stringify(state));
export const fixtureHttp: Http = async (method, path, raw) => {
  const state = await read();
  const body = raw as Record<string, unknown> | undefined;
  const run = method === "GET"
    ? new URL(path, "https://gateway.test").searchParams.get("run_id")
    : body?.run_id;
  if (state.root && state.root !== run) return { status: 409, body: {} };
  state.root = run;
  state.calls.push({ method, path });
  if (path.includes("/private-material")) {
    await save(state);
    return {
      status: 200,
      body: {
        data: {
          pi_token: Deno.env.get("ORBIT_RESUME_TEST_TOKEN"),
        },
      },
    };
  }
  if (
    path.endsWith("/failure") &&
    ["queued", "running"].includes(state.operation.status)
  ) {
    state.operation.status = "failed";
    state.operation.result = null;
  }
  if (body?.status) {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    await save(state);
    if (body.status === "succeeded" && state.loseFinal) {
      state.loseFinal = false;
      await save(state);
      throw new Error("Lost terminal reply");
    }
  }
  await save(state);
  return { status: 200, body: { data: state.operation } };
};

export const fixtureAction: Parameters<typeof nativeResume>[1] = async (
  stage,
  input,
  gateway,
  guard,
) => {
  if (stage !== "guest") await guard();
  const state = await read();
  state.stages.push({
    stage,
    node_id: stage === "guest" ? input.guest_node_id : input.node_id,
  });
  await save(state);
  if (stage === "guest" && state.failGuest) {
    throw new Error("agent.version_mismatch");
  }
  if (stage === "pi") {
    await gateway.withPrivateMaterial((material) => {
      if (material.pi_token !== Deno.env.get("ORBIT_RESUME_TEST_TOKEN")) {
        throw new Error("Private token missing");
      }
      return Promise.resolve();
    });
  }
};
