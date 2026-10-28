import type { Http } from "../../automatic-deploy/gateway.ts";

// Disposable native workflow fixture. No real Gateway or Node is contacted.
export const fixtureHttp: Http = async (_method, path, raw) => {
  const state = JSON.parse(await Deno.readTextFile("native-tools.json"));
  const body = raw as Record<string, unknown>;
  state.calls.push({ path, body });
  let data: unknown = state.operation;
  if (path.endsWith("/claim")) {
    data =
      body.kind === state.operation.kind && state.operation.status === "queued"
        ? state.operation
        : null;
    if (data) state.operation.status = "running";
  }
  let lose = false;
  if (path.endsWith("/heartbeat") && state.loseAdmission) {
    state.loseAdmission = false;
    lose = true;
  }
  if (path.endsWith("/run")) {
    const key = String(body.key);
    if (!(key in state.receipts)) {
      const name = String(body.name);
      let receipt: Record<string, unknown> = {};
      if ((name.endsWith("-inventory") || name.endsWith("-adoption"))) {
        receipt = { installed_version: state.installed, block: null };
      } else if (name.endsWith("-version")) receipt = { version: "1.0.0" };
      else if (name.endsWith("-candidate")) receipt = { version: "7.0.0" };
      else if (name.endsWith("-removal-plan")) {
        receipt = { packages: ["is-number"] };
      } else if (key === "package-mutate") {
        state.mutations++;
        state.installed = state.operation.kind === "tool.remove"
          ? null
          : "7.0.0";
      }
      state.receipts[key] = receipt;
    }
    data = state.receipts[key];
    if (key === "package-mutate" && state.loseRun) {
      state.loseRun = false;
      lose = true;
    }
  }
  if (path.endsWith("/step")) {
    const name = String(body.name);
    const step = state.operation.steps.find((s: { name: string }) =>
      s.name === name
    );
    if (step) step.status = body.status;
    else state.operation.steps.push({ name, status: body.status });
    if (body.status === "succeeded") {
      state.operation.step_results[name] = body.result;
    }
  }
  if (path.endsWith("/complete")) {
    state.operation.status = body.status;
    if (state.completions.length === 0) state.completions.push(body);
    if (state.loseCompletion) {
      state.loseCompletion = false;
      lose = true;
    }
  }
  await Deno.writeTextFile("native-tools.json", JSON.stringify(state));
  if (lose) throw new Error("Lost native Tool response");
  return { status: 200, body: { data } };
};
