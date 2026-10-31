import type { Http } from "../../../extensions/behaviors/automatic-deploy/gateway.ts";
const path = "native-merge.json";
const load = async () => JSON.parse(await Deno.readTextFile(path));
const save = async (state: unknown) =>
  await Deno.writeTextFile(path, JSON.stringify(state));
export const fixtureHttp: Http = async (method, url, raw) => {
  const state = await load();
  state.calls.push(`${method} ${url}`);
  if (url.includes("private-material")) {
    const token = Deno.env.get("DEPENDENCY_MERGE_TEST_TOKEN");
    if (!token) throw new Error("Fixture credential missing");
    await save(state);
    return { status: 200, body: { data: { token } } };
  }
  const body = raw as Record<string, unknown>;
  if (method === "POST") {
    if (body.status === "succeeded" && state.loseProjection) {
      state.loseProjection = false;
      await save(state);
      throw new Error("Lost projection");
    }
    state.operation.status = body.status;
    state.operation.result = body.result ?? state.operation.result;
  }
  await save(state);
  return { status: 200, body: { data: state.operation } };
};
export const fixtureGithub: typeof fetch = async (url, init) => {
  const state = await load();
  if (
    url !== "https://api.github.com/repos/acme/widgets/pulls/3" &&
    url !== "https://api.github.com/repos/acme/widgets/pulls/3/merge"
  ) throw new Error("Unexpected endpoint");
  if (
    (init?.headers as Record<string, string>).Authorization !==
      `Bearer ${Deno.env.get("DEPENDENCY_MERGE_TEST_TOKEN")}`
  ) throw new Error("Wrong credential");
  if (init?.method === "GET") {
    if (state.loseBeforeMerge) {
      state.loseBeforeMerge = false;
      await save(state);
      throw new Error("Lost observation");
    }
    return Response.json(state.pr);
  }
  const body = JSON.parse(String(init?.body));
  if (
    init?.method !== "PUT" || body.sha !== state.operation.input.head_sha ||
    body.merge_method !== "squash"
  ) throw new Error("Wrong merge request");
  state.writes++;
  state.pr.merged = true;
  state.pr.state = "closed";
  state.pr.merge_commit_sha = "b".repeat(40);
  await save(state);
  if (state.loseMerge) {
    state.loseMerge = false;
    await save(state);
    throw new Error(Deno.env.get("DEPENDENCY_MERGE_TEST_TOKEN"));
  }
  return Response.json({ merged: true, sha: "b".repeat(40) });
};
export const fixtureConfiguration = async () => {
  const state = await load();
  state.refreshes = (state.refreshes ?? 0) + 1;
  await save(state);
  return { ok: !state.refreshFail };
};
export const fixtureQuery = async () => [{
  projectId: 20,
  enabled: !(await load()).disabled,
  repository: "acme/widgets",
  defaultBranch: "main",
  smokeWorkflow: ".github/workflows/production-smoke.yml",
}];
