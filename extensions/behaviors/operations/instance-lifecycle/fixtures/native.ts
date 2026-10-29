/** The native Swamp process uses this disposable Gateway, with receipts durable across processes. */
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-lifecycle.json"));
  const input = raw as Record<string, unknown> | undefined;
  state.calls.push({ method, path, input });
  let data: unknown = state.operation;
  if (path.endsWith("/claimable")) {
    const eligible =
      !["succeeded", "failed", "cancelled"].includes(state.operation.status) &&
      (state.operation.status !== "waiting" ||
        state.childStatus === "succeeded");
    data = {
      kinds: [{
        kind: state.operation.kind,
        claimable: eligible ? 1 : 0,
        oldest_at: eligible ? "2026-10-28T10:00:00Z" : null,
      }],
    };
  }
  if (path.endsWith("/claim")) {
    if (
      input!.kind !== state.operation.kind ||
      state.operation.status === "succeeded" ||
      (state.operation.status === "waiting" &&
        state.childStatus !== "succeeded")
    ) {
      data = null;
    } else {
      state.operation.worker = input!.worker;
      state.operation.status = "running";
      if (state.loseClaimReply) {
        state.loseClaimReply = false;
        await Deno.writeTextFile(
          "native-lifecycle.json",
          JSON.stringify(state),
        );
        throw new Error("Lost admission reply");
      }
    }
  } else if (method === "POST" && input!.worker !== state.operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  if (path.endsWith("/children")) {
    if (method === "POST") {
      if (
        input!.key !== "route-update" || "input" in input! ||
        "node_id" in input!
      ) throw new Error("Child admission must use its recorded key");
      if (!state.childAdmitted) {
        state.childAdmitted = true;
        state.childAdmissions++;
      }
      data = { id: 200 };
    } else {
      data = [{
        id: 200,
        key: "route-update",
        kind: "route.update",
        node_id: 9,
        status: state.childStatus,
        error_code: null,
        result: state.childStatus === "succeeded" ? { route_id: 30 } : null,
      }];
    }
  }
  if (path.endsWith("/wait")) {
    state.operation.status = "waiting";
    state.operation.worker = null;
    data = state.operation;
  }
  if (path.includes("/receipts/")) {
    const key = decodeURIComponent(path.split("/").at(-1)!);
    const result = state.receipts[key] ?? null;
    data = {
      effect_key: `op-100.${key}`,
      state: result ? "succeeded" : "unknown",
      result,
      exit_code: result ? 0 : null,
      finished_at: result ? "2026-10-28T10:00:00Z" : null,
    };
  }
  if (path.endsWith("/run")) {
    const key = String(input!.key);
    const descriptor = state.operation.execution_context.descriptors.find((
      d: { key: string },
    ) => d.key === key);
    if (
      !descriptor || "node_id" in input! ||
      descriptor.transport !== input!.transport ||
      descriptor.name !== input!.name ||
      JSON.stringify(descriptor.input) !== JSON.stringify(input!.input)
    ) {
      throw new Error("Invocation changed admitted intent");
    }
    state.invocations[key] = (state.invocations[key] ?? 0) + 1;
    if (!state.receipts[key]) {
      state.effects[key] = (state.effects[key] ?? 0) + 1;
      state.receipts[key] = key === "rename-inspect"
        ? { source_verified: true, branch: "feature" }
        : { lifecycle_complete: true, instance_id: 501 };
    }
    data = state.receipts[key];
    if (state.loseRunReply === key) {
      state.loseRunReply = null;
      await Deno.writeTextFile("native-lifecycle.json", JSON.stringify(state));
      throw new Error("Lost lifecycle effect reply");
    }
  }
  if (path.endsWith("/step")) {
    state.operation.steps = state.operation.steps.filter((
      step: { name: string },
    ) => step.name !== input!.name);
    state.operation.steps.push({ name: input!.name, status: input!.status });
    if (input!.status === "succeeded") {
      state.operation.step_results[String(input!.name)] = input!.result;
    }
  }
  if (path.endsWith("/complete")) {
    if (
      !state.operation.step_results["lifecycle-finish"] ||
      JSON.stringify(input!.result) !==
        JSON.stringify(state.receipts["lifecycle-finish"])
    ) {
      throw new Error("Completion lacks keyed step proof");
    }
    state.operation.status = input!.status;
    state.operation.result = input!.result;
    state.operation.error_code = input!.error_code;
    state.completions.push(input);
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await Deno.writeTextFile("native-lifecycle.json", JSON.stringify(state));
      throw new Error("Lost lifecycle completion reply");
    }
  }
  await Deno.writeTextFile("native-lifecycle.json", JSON.stringify(state));
  return { status: 200, body: { data } };
}
