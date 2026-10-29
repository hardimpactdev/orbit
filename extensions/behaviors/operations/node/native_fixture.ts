import type { Http } from "../../automatic-deploy/gateway.ts";

/** Disposable Gateway state for local process recovery. */
export const fixtureHttp: Http = async (_method, path, raw) => {
  const file = "native-node.json";
  const state = JSON.parse(await Deno.readTextFile(file));
  const body = (raw ?? {}) as Record<string, unknown>;
  let data: unknown = path.startsWith("/api/v1/operations/")
    ? {
      ...state.operation,
      result: state.operation.result ?? null,
      error_code: state.operation.error_code ?? null,
    }
    : state.operation;
  state.calls.push(path);
  if (path.endsWith("/claimable")) {
    data = {
      kinds: [{
        kind: state.operation.kind,
        claimable: state.operation.status === "queued" ? 1 : 0,
        oldest_at: null,
      }],
    };
  } else if (path.endsWith("/claim")) {
    if (
      body.kind !== state.operation.kind || state.operation.status !== "queued"
    ) data = null;
    else state.operation.status = "running";
  } else if (path.includes("/receipts/")) {
    data = {
      effect_key: "op-7.node-storage-root",
      state: state.receipt === null ? "unknown" : "succeeded",
      result: state.receipt,
      exit_code: null,
      finished_at: null,
    };
  } else if (path.endsWith("/run")) {
    if (
      body.key !== "node-storage-root" || body.transport !== "agent" ||
      body.name !== "node.prepare-storage-root"
    ) throw new Error("Unexpected Node effect");
    if (state.receipt === null) {
      state.runs++;
      state.receipt = {};
    }
    data = state.receipt;
    if (state.loseRunReply) {
      state.loseRunReply = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost Node reply");
    }
  } else if (path.endsWith("/step")) {
    const step = state.operation.steps.find((step: { name: string }) =>
      step.name === body.name
    );
    if (step) step.status = body.status;
    else state.operation.steps.push({ name: body.name, status: body.status });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
  } else if (path.endsWith("/complete")) {
    if (state.loseBeforeCommit) {
      state.loseBeforeCommit = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost completion request");
    }
    state.operation.status = body.status;
    state.operation.result = body.result;
    state.operation.error_code = body.error_code;
    state.completions.push(body);
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost completion reply");
    }
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data } };
};
