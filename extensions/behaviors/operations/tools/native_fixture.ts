import { fixturePlan } from "./plan_fixture.ts";
import { Operation } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

// Disposable native workflow fixture. No real Gateway or Node is contacted.
export const fixtureHttp: Http = async (method, path, raw) => {
  const state = JSON.parse(await Deno.readTextFile("native-tools.json"));
  const body = (raw ?? {}) as Record<string, unknown>;
  state.calls.push({ method, path, body });
  state.operation.execution_context = fixturePlan(
    Operation.parse(state.operation),
  );
  let data: unknown = state.operation;
  if (path === `/api/v1/operations/${state.operation.id}`) {
    await Deno.writeTextFile("native-tools.json", JSON.stringify(state));
    return {
      status: 200,
      body: {
        data: {
          ...state.operation,
          result: state.completions[0]?.result ?? null,
          error_code: state.completions[0]?.error_code ?? null,
        },
      },
    };
  }
  if (path.endsWith("/claimable")) {
    data = {
      kinds: [{
        kind: state.operation.kind,
        claimable: state.operation.status === "queued" ||
            state.operation.status === "running" && state.leaseExpired
          ? 1
          : 0,
        oldest_at: "2026-10-29T00:00:00Z",
      }],
    };
  }
  let lose = false;
  if (path.endsWith("/claim")) {
    data = body.kind === state.operation.kind &&
        (state.operation.status === "queued" ||
          state.operation.status === "running" &&
            (state.worker === body.worker || state.leaseExpired))
      ? state.operation
      : null;
    if (data) {
      state.worker = body.worker;
      state.operation.status = "running";
      state.leaseExpired = false;
    }
    if (state.loseAdmission) {
      state.loseAdmission = false;
      lose = true;
    }
  }
  if (path.endsWith("/step") && state.loseBeforeEffect) {
    state.loseBeforeEffect = false;
    lose = true;
  }
  if (path.includes("/receipts/")) {
    const key = decodeURIComponent(path.split("/receipts/")[1]);
    const receipt = state.receipts[key];
    const terminal = receipt !== undefined;
    data = {
      effect_key: `op-${state.operation.id}.${key}`,
      state: terminal
        ? (receipt.error_code ? "failed" : "succeeded")
        : "unknown",
      exit_code: terminal ? (receipt.error_code ? 1 : 0) : null,
      result: terminal
        ? (Object.keys(receipt).length === 0 ? [] : receipt)
        : null,
      finished_at: terminal ? "2026-10-29T06:46:20Z" : null,
    };
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
    data = Object.keys(state.receipts[key]).length === 0
      ? []
      : state.receipts[key];
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
      state.operation.step_results[name] =
        Object.keys(body.result as object).length === 0 ? [] : body.result;
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
