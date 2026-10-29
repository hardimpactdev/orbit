/** Native workflow fixture. Its file is confined to the disposable test repository. */
export async function fixtureHttp(
  method: string,
  path: string,
  body?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("native-executor.json"));
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
      input.name !== "firewall.rule" ||
      command.node_id !== state.operation.node_id ||
      command.operation !==
        (state.operation.kind === "firewall.remove" ? "remove" : "converge") ||
      ["name", "action", "source", "port", "protocol"].some((field) =>
        command[field] !== state.operation.input[field]
      )
    ) throw new Error("Invocation does not match the admitted firewall intent");
    if (!state.receipt) {
      state.runs++;
      state.receipt = {
        backend: state.operation.kind === "firewall.remove"
          ? "absent"
          : "active",
      };
    }
    data = state.receipt;
    if (state.loseRunReply) {
      state.loseRunReply = false;
      await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
      throw new Error(
        "Native fixture lost the Node response after applying the rule",
      );
    }
  }
  if (path.endsWith("/complete")) {
    const step = state.operation.kind === "firewall.remove"
      ? "ufw-remove"
      : "ufw-converge";
    if (
      input.status === "succeeded" &&
      (!state.receipt || !state.operation.step_results[step] ||
        JSON.stringify(state.receipt) !== JSON.stringify(input.result))
    ) {
      throw new Error("Completion is missing its successful Node step");
    }
    state.operation.status = input.status;
    state.completions.push(input);
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
      throw new Error("Completion committed before the response was lost");
    }
  }
  await Deno.writeTextFile("native-executor.json", JSON.stringify(state));
  return { status: 200, body: { data } };
}
