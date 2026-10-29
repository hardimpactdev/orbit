/** Used only inside the disposable native workflow test repository. */
export async function fixtureHttp(
  _method: string,
  path: string,
  raw?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("executor.json"));
  const body = (raw ?? {}) as Record<string, unknown>;
  let data: unknown = state.operation;
  if (path.endsWith("/claimable")) {
    data = {
      kinds: [{
        kind: state.operation.kind,
        claimable:
          ["succeeded", "failed", "cancelled"].includes(state.operation.status)
            ? 0
            : 1,
        oldest_at: "2026-10-28T22:00:00Z",
      }],
    };
  }
  if (path.endsWith("/claim")) {
    if (
      body.kind !== state.operation.kind ||
      state.operation.status === "succeeded"
    ) data = null;
    else {
      state.operation.worker = body.worker;
      state.operation.status = "running";
    }
  } else if (_method !== "GET" && body.worker !== state.operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  if (path.includes("/receipts/")) {
    const key = path.split("/").at(-1)!;
    const result = state.receipts[key];
    data = {
      effect_key: `op-${state.operation.id}.${key}`,
      state: result ? result.error_code ? "failed" : "succeeded" : "unknown",
      exit_code: result ? result.error_code ? 1 : 0 : null,
      result: result ?? null,
      finished_at: result ? "2026-10-28T22:00:00Z" : null,
    };
  }
  if (path.endsWith("/run")) {
    if (body.transport === "agent" && body.name !== "process.status") {
      state.runPosts = (state.runPosts ?? 0) + 1;
    }
    if (!["agent", "gateway"].includes(body.transport) || "node_id" in body) {
      throw new Error("Invalid transport");
    }
    const key = String(body.key);
    if (!(key in state.receipts)) {
      if (body.transport === "agent") state.calls.push(body);
      state.receipts[key] = body.transport === "gateway"
        ? { ready: true, state: "inactive" }
        : body.name === "process.stage-unit"
        ? { staged: true, previous: false, state: null }
        : body.name === "process.activate-unit"
        ? { backup_left: false }
        : body.name === "process.status"
        ? {
          state: state.operation.kind === "process.destroy"
            ? "absent"
            : "active",
        }
        : {};
    }
    data = state.receipts[key];
    if (state.loseRunReply) {
      state.loseRunReply = false;
      await Deno.writeTextFile("executor.json", JSON.stringify(state));
      throw new Error("Lost native command reply");
    }
  }
  if (path.endsWith("/step")) {
    const existing = state.operation.steps.find((step: { name: string }) =>
      step.name === body.name
    );
    if (existing) existing.status = body.status;
    else state.operation.steps.push({ name: body.name, status: body.status });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
  }
  if (path.endsWith("/complete")) {
    state.operation.status = body.status;
    state.completions.push(body);
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await Deno.writeTextFile("executor.json", JSON.stringify(state));
      throw new Error("Lost native completion reply");
    }
  }
  await Deno.writeTextFile("executor.json", JSON.stringify(state));
  return { status: 200, body: { data } };
}
