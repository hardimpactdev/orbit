/** Native workflow fixture. Its file is confined to the disposable test repository. */
export async function fixtureHttp(
  method: string,
  path: string,
  body?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
  if (path.includes("/private-material?")) {
    return {
      status: 200,
      body: { data: { upcloud_token: Deno.env.get("SWEEP_PRIVATE_FIXTURE") } },
    };
  }
  if (path.startsWith(`/api/v1/direct-operations/${state.operation.id}`)) {
    state.directCalls = [...(state.directCalls ?? []), { method, path }];
    if (method === "POST") {
      const result = body as Record<string, unknown>;
      state.operation.status = result.status;
      state.operation.result = result.result ?? null;
      if (state.loseDirectReply && result.status === "succeeded") {
        state.loseDirectReply = false;
        await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
        throw new Error("Lost direct completion reply");
      }
    }
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
    return { status: 200, body: { data: state.operation } };
  }
  if (path === `/api/v1/operations/${state.operation.id}`) {
    return {
      status: 200,
      body: {
        data: {
          ...state.operation,
          result: state.completions.at(-1)?.result ?? null,
          error_code: state.completions.at(-1)?.error_code ?? null,
        },
      },
    };
  }
  if (path.endsWith("/claimable")) {
    state.discoveryCalls = (state.discoveryCalls ?? 0) + 1;
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
    if (method !== "GET" || body !== undefined) {
      throw new Error("Discovery must be a read");
    }
    return {
      status: 200,
      body: {
        data: {
          kinds: [{
            kind: state.operation.kind,
            claimable: state.operation.status === "succeeded" ? 0 : 1,
            oldest_at: "2026-10-29T00:00:00Z",
          }],
        },
      },
    };
  }
  if (path.includes("/receipts/")) {
    const pending = state.receiptPendingOnce === true;
    state.receiptPendingOnce = false;
    await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
    return {
      status: 200,
      body: {
        data: {
          state: pending ? "running" : state.receipt ? "succeeded" : "unknown",
          result: pending ? null : state.receipt,
        },
      },
    };
  }
  const input = body as Record<string, unknown>;
  let data: unknown = state.operation;
  if (path.endsWith("/claim")) {
    if (
      input.kind !== state.operation.kind ||
      state.operation.status === "succeeded"
    ) data = null;
    else {
      state.operation.worker = input.worker;
      state.operation.status = "running";
    }
  } else if (input.worker !== state.operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  if (path.endsWith("/step")) {
    state.operation.steps = [{ name: input.name, status: input.status }];
    if (input.status === "succeeded") {
      state.operation.step_results[String(input.name)] = input.result;
    }
  }
  if (path.endsWith("/run")) {
    const command = input.input as Record<string, unknown>;
    if (
      "node_id" in input || input.transport !== "agent" ||
      input.name !== "incus.vm" ||
      JSON.stringify(command) !==
        JSON.stringify({
          vm: state.operation.input.vm,
          operation: state.operation.input.operation,
        })
    ) throw new Error("Invocation differs from VM intent");
    if (!state.receipt) {
      state.runs++;
      state.receipt = { ready: true };
    }
    data = state.receipt;
    if (state.loseRunReply) {
      state.loseRunReply = false;
      state.receiptPendingOnce = true;
      await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
      throw new Error(
        "Native fixture lost the Node response after applying the rule",
      );
    }
  }
  if (path.endsWith("/complete")) {
    const step = "incus-start";
    if (
      input.status === "succeeded" &&
      (!state.receipt || !state.operation.step_results[step] ||
        JSON.stringify(state.receipt) !== JSON.stringify(input.result))
    ) {
      throw new Error("Completion is missing its successful Node step");
    }
    state.operation.status = input.status;
    state.completions.push(input);
  }
  await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
  return { status: 200, body: { data } };
}
