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
  } else if (path.endsWith("/children")) {
    if (_method === "POST") {
      if (
        body.key !== "add-bootstrap-probe" ||
        Object.keys(body).sort().join() !== "key,worker"
      ) throw new Error("Unexpected child intent");
      state.child ??= {
        id: 20,
        key: "add-bootstrap-probe",
        kind: "node.bootstrap_probe",
        node_id: 2,
        status: "queued",
        error_code: null,
        result: {},
      };
      data = {
        ...state.child,
        input: {},
        cancel_requested: false,
        step_results: {},
      };
      if (state.loseChildReply) {
        state.loseChildReply = false;
        await Deno.writeTextFile(file, JSON.stringify(state));
        throw new Error("Lost child admission reply");
      }
    } else data = state.child ? [state.child] : [];
  } else if (path.endsWith("/wait")) {
    state.operation.status = "waiting";
    data = { ...state.operation, worker: null, lease_seconds: 0 };
  } else if (path.includes("/receipts/")) {
    data = {
      effect_key: "op-7.node-storage-root",
      state: state.receipt === null ? "unknown" : "succeeded",
      result: state.receipt,
      exit_code: null,
      finished_at: null,
    };
  } else if (path.endsWith("/run")) {
    const parent = state.operation.kind === "node.add";
    if (parent) {
      if (
        body.key !== "node-lifecycle-result" || body.transport !== "gateway" ||
        body.name !== "node.lifecycle-result" ||
        state.child?.status !== "succeeded"
      ) throw new Error("Unproven lifecycle result");
    } else if (
      body.key !== "node-storage-root" || body.transport !== "agent" ||
      body.name !== "node.prepare-storage-root"
    ) throw new Error("Unexpected Node effect");
    if (state.receipt === null) {
      state.runs++;
      state.receipt = parent ? { node_plan_proven: true, enrolled: true } : {};
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
