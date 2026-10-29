import type { Http } from "../../automatic-deploy/gateway.ts";

/** Disposable fake Gateway for native process-restart tests. Never used by the deployed behavior. */
export const fixtureHttp: Http = async (_method, path, raw) => {
  const state = JSON.parse(await Deno.readTextFile("fleet-native.json"));
  const body = (raw ?? {}) as Record<string, unknown>;
  state.calls.push({ path, body });
  const save = () =>
    Deno.writeTextFile("fleet-native.json", JSON.stringify(state));
  let data: unknown = state.operation;
  if (path.startsWith("/api/v1/operations/")) {
    data = {
      ...state.operation,
      result: state.completions.at(-1)?.result ?? null,
      error_code: state.completions.at(-1)?.error_code ?? null,
    };
  }
  if (path.endsWith("/visit-policy")) {
    data = {
      daytime: state.daytime ?? true,
      window_open: state.windowOpen ?? true,
    };
  }
  if (path.endsWith("/children")) {
    if (_method === "POST") {
      state.child = {
        id: 20,
        key: "footprint",
        kind: "node.converge",
        node_id: 2,
        status: "queued",
      };
    }
    data = _method === "POST" ? state.child : state.child ? [state.child] : [];
  }
  if (path.endsWith("/wait")) state.operation.status = "waiting";
  if (path.endsWith("/claimable")) {
    const ready = state.operation.status !== "waiting" || state.child &&
        ["succeeded", "failed", "cancelled"].includes(state.child.status);
    data = {
      kinds: [{
        kind: state.operation.kind,
        claimable: ready &&
            !["succeeded", "failed", "cancelled"].includes(
              state.operation.status,
            )
          ? 1
          : 0,
        oldest_at: null,
      }],
    };
  }
  if (path.endsWith("/claim")) {
    const ready = state.operation.status !== "waiting" ||
      state.child &&
        ["succeeded", "failed", "cancelled"].includes(state.child.status);
    if (ready && state.operation.status === "waiting") {
      state.operation.execution_context = {
        descriptors: [],
        result: { wait_resume: true },
      };
    }
    data = ready && body.kind === state.operation.kind &&
        state.operation.status !== "succeeded"
      ? state.operation
      : null;
  }
  if (path.endsWith("/heartbeat")) state.operation.status = "running";
  if (path.includes("/receipts/")) {
    const key = decodeURIComponent(path.split("/receipts/")[1]);
    data = {
      effect_key: `op-${state.operation.id}.${key}`,
      state: state.runningReceipts?.includes(key)
        ? "running"
        : state.receipts[key]
        ? "succeeded"
        : "unknown",
      exit_code: state.receipts[key] ? 0 : null,
      result: state.receipts[key] ?? null,
      finished_at: null,
    };
  }
  if (path.endsWith("/run")) {
    const key = String(body.key);
    if (!state.receipts[key]) {
      state.receipts[key] = body.name === "fleet.recipe-begin"
        ? { started: true }
        : body.name === "php.install-build"
        ? { outcome: "applied" }
        : body.name === "recipe.apply"
        ? { healthy: true, changed_packages: ["curl"] }
        : body.name === "cli.install"
        ? { installed: state.operation.input.release.version, configured: true }
        : body.name === "fleet.cli-converge-result"
        ? {
          completed: true,
          outcome: state.operation.input.skip_reason
            ? "skipped"
            : "cli_installed",
          reason: state.operation.input.skip_reason,
          version: state.operation.input.skip_reason
            ? null
            : state.operation.input.release.version,
          configured: !state.operation.input.skip_reason,
        }
        : body.name === "fleet.verify"
        ? { passed: true }
        : {
          identity: state.operation.input.identity,
          agent_sha256: state.operation.input.agent_sha256,
          cli_version: state.operation.input.cli_version,
        };
      if (["recipe.apply", "cli.install"].includes(String(body.name))) {
        state.mutations++;
      }
    }
    data = state.receipts[key];
    if (
      body.name === (state.loseRunName ?? "recipe.apply") && state.loseRunReply
    ) {
      state.loseRunReply = false;
      await save();
      throw new Error("Lost Node reply after application");
    }
  }
  if (path.endsWith("/step")) {
    state.operation.steps = state.operation.steps.filter((
      s: { name: string },
    ) => s.name !== body.name);
    state.operation.steps.push({
      name: body.name,
      status: body.status,
      error_code: body.error_code ?? null,
    });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
  }
  if (path.endsWith("/complete")) {
    state.operation.status = body.status;
    if (state.completions.length === 0) state.completions.push(body);
    if (state.loseCompletionReply) {
      state.loseCompletionReply = false;
      await save();
      throw new Error("Lost completion reply");
    }
  }
  await save();
  return { status: 200, body: { data } };
};
