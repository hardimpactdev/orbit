/** Fake Gateway for fresh-process recovery tests, confined to a disposable repo. */
export async function fixtureHttp(
  _method: string,
  path: string,
  raw?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
  const body = (raw ?? {}) as Record<string, unknown>;
  const operation = state.operation;
  if (_method === "GET" && path.includes("/receipts/")) {
    const key = decodeURIComponent(path.split("/receipts/")[1]);
    const result = state.receipts[key] ?? null;
    state.receiptReads = [...(state.receiptReads ?? []), key];
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
    return {
      status: 200,
      body: {
        data: {
          effect_key: `op-${operation.id}.${key}`,
          exit_code: null,
          finished_at: null,
          state: result === null ? "unknown" : "succeeded",
          result,
        },
      },
    };
  }
  if (_method === "GET" && path === `/api/v1/operations/${operation.id}`) {
    return { status: 200, body: { data: operation } };
  }
  if (path.endsWith("/claimable")) {
    return {
      status: 200,
      body: {
        data: {
          kinds: [{
            kind: operation.kind,
            claimable: operation.status === "succeeded" ? 0 : 1,
            oldest_at: null,
          }],
        },
      },
    };
  }
  if (path.endsWith("/claim")) {
    operation.worker = body.worker;
    if (body.kind !== operation.kind) throw new Error("Wrong claim kind");
    state.claims = (state.claims ?? 0) + 1;
    operation.status = "running";
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
  }
  if (body.worker !== operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  let data: unknown = operation;
  if (path.endsWith("/step")) {
    const index = operation.steps.findIndex((step: { name: string }) =>
      step.name === body.name
    );
    const step = { name: body.name, status: body.status };
    if (index < 0) operation.steps.push(step);
    else operation.steps[index] = step;
    if (body.status === "succeeded") {
      operation.step_results[String(body.name)] = body.result;
    }
  }
  if (path.endsWith("/run")) {
    const descriptor = operation.execution_context.descriptors.find((
      item: { key: string },
    ) => item.key === body.key);
    if (
      !descriptor || "node_id" in body || body.name !== descriptor.name ||
      body.transport !== descriptor.transport ||
      JSON.stringify(body.input) !== JSON.stringify(descriptor.input)
    ) {
      throw new Error("Invocation differs from the admitted descriptor");
    }
    const key = String(body.key);
    state.invocations = [...(state.invocations ?? []), key];
    if (!(key in state.receipts)) {
      state.effects.push(key);
      state.receipts[key] = state.runResults?.[key] ??
        (key === "verification"
          ? {
            deployment_id: 51,
            release: null,
            selected_release: null,
            commit: "a".repeat(40),
          }
          : key.startsWith("before_activation")
          ? { exit_code: 0, timed_out: false }
          : { commit: "a".repeat(40) });
    }
    data = state.receipts[key];
    if (state.loseRunReply === key) {
      state.loseRunReply = null;
      await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
      return {
        status: 409,
        body: { error: { code: "operation.execution_uncertain" } },
      };
    }
  }
  if (path.endsWith("/complete")) {
    const required = operation.execution_context.descriptors;
    if (
      body.status === "succeeded" &&
      required.some((item: { key: string; step: string }) =>
        !(item.key in state.receipts) || !(item.step in operation.step_results)
      )
    ) {
      throw new Error("Completion lacks required execution evidence");
    }
    operation.status = body.status;
    operation.result = body.result;
    operation.error_code = body.error_code;
    state.completions.push(body);
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
      throw new Error("Lost reply after completion");
    }
  }
  await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
  return { status: 200, body: { data } };
}
