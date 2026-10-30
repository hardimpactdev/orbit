/** File-backed Gateway fixture confined to the disposable native test repository. */
export async function fixtureHttp(
  _method: string,
  path: string,
  body?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
  const input = (body ?? {}) as Record<string, unknown>;
  let data: unknown = state.operation;
  state.calls ??= [];
  state.calls.push({ method: _method, path, worker: input.worker ?? null });
  if (path.endsWith("/claimable")) {
    data = {
      kinds: [{
        kind: state.operation.kind,
        claimable:
          ["succeeded", "failed", "cancelled"].includes(state.operation.status)
            ? 0
            : 1,
        oldest_at: "2026-10-29T00:00:00Z",
      }],
    };
  } else if (path.startsWith("/api/v1/operations/")) {
    const completion = state.completions.at(-1);
    data = {
      ...state.operation,
      result: completion?.result ?? null,
      error_code: completion?.error_code ?? null,
    };
  } else if (path.endsWith("/claim")) {
    if (
      input.kind !== state.operation.kind ||
      state.operation.status === "succeeded"
    ) data = null;
    else {
      state.operation.worker = input.worker;
      state.operation.status = "running";
    }
  } else if (
    !path.includes("/receipts/") && input.worker !== state.operation.worker
  ) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  if (path.endsWith("/step")) {
    state.operation.steps = [{ name: input.name, status: input.status }];
    if (input.status === "succeeded") {
      state.operation.step_results[String(input.name)] = input.result;
    }
  }
  if (path.includes("/receipts/")) {
    state.receiptReads = (state.receiptReads ?? 0) + 1;
    data = {
      effect_key: `op-12.${path.split("/").at(-1)}`,
      state: state.receipt ? "succeeded" : "unknown",
      exit_code: state.receipt ? 0 : null,
      result: state.receipt,
      finished_at: null,
    };
  }
  if (path.endsWith("/run") && state.operation.input.preference_only === true) {
    if (
      input.transport !== "gateway" || input.name !== "metrics.receipt" ||
      input.key !== "integration-receipt" ||
      JSON.stringify(input.input) !== JSON.stringify({ backend: "recorded" })
    ) throw new Error("Preference intent changed");
    if (!state.receipt) {
      state.gatewayRuns = (state.gatewayRuns ?? 0) + 1;
      state.receipt = { backend: "recorded" };
    }
    data = state.receipt;
  } else if (path.endsWith("/run")) {
    const command = input.input as Record<string, unknown>;
    if (
      "node_id" in input || input.transport !== "agent" ||
      input.name !== "metrics.node-exporter" ||
      input.key !== "node-exporter-converge" ||
      command.address !== state.operation.input.address ||
      command.metrics_address !== state.operation.input.metrics_address ||
      JSON.stringify(command.action) !==
        JSON.stringify({ operation: "converge" })
    ) throw new Error("Integration intent changed");
    if (!state.receipt) {
      state.runs++;
      state.receipt = { changed: true };
    }
    data = state.receipt;
    if (state.loseRunReply) {
      state.loseRunReply = false;
      await save();
      throw new Error("Lost native Node reply");
    }
  }
  if (path.endsWith("/complete")) {
    if (
      !state.operation
        .step_results[
          state.operation.input.preference_only === true
            ? "integration-receipt"
            : "node-exporter-converge"
        ] ||
      JSON.stringify(input.result) !== JSON.stringify(state.receipt)
    ) throw new Error("Unproven completion");
    if (!state.completions.length) state.completions.push(input);
    state.operation.status = input.status;
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await save();
      throw new Error("Lost native completion reply");
    }
  }
  await save();
  return { status: 200, body: { data } };
  async function save() {
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
  }
}
