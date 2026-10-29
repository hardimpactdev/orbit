import type { Http } from "../../automatic-deploy/gateway.ts";

/** Disposable Gateway boundary for the native workflow tests. */
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
    else {
      state.operation.status = "running";
      state.worker = body.worker;
    }
  } else if (
    [
      "node.converge",
    ].includes(state.operation.kind) &&
    path.endsWith("/children")
  ) {
    state.children ??= [];
    if (_method === "POST") {
      let child = state.children.find((c: { key: string }) =>
        c.key === body.key
      );
      if (!child) {
        const intent = state.operation.state.child_intents.find((
          intent: { key: string },
        ) => intent.key === body.key);
        if (
          !intent ||
          (Object.keys(body).length !== 2 || body.worker !== state.worker)
        ) {
          throw new Error("Child intent missing");
        }
        child = {
          id: 20 + state.children.length,
          key: body.key,
          kind: intent.kind,
          node_id: intent.node_id,
          status: state.childStatus ?? "succeeded",
          error_code: null,
          result: { changed: false },
        };
        state.children.push(child);
      }
      data = { ...child, input: {}, cancel_requested: false, step_results: {} };
      if (state.loseChildReply) {
        state.loseChildReply = false;
        await Deno.writeTextFile(file, JSON.stringify(state));
        throw new Error("Lost child reply");
      }
    } else data = state.children;
  } else if (
    state.operation.kind !== "node.settings" && path.includes("/receipts/")
  ) {
    const result = state.receipts
      ?.[decodeURIComponent(path.split("/").at(-1)!)];
    data = {
      effect_key: `op-7.${decodeURIComponent(path.split("/").at(-1)!)}`,
      exit_code: null,
      finished_at: null,
      state: result ? "succeeded" : "unknown",
      result: result ?? null,
    };
  } else if (
    state.operation.kind !== "node.settings" && path.endsWith("/run")
  ) {
    state.keys.push(body.key);
    state.receipts ??= {};
    const key = String(body.key);
    if (!Object.hasOwn(state.receipts, key)) {
      if (body.transport !== "gateway") state.runs++;
      state.receipts[key] = body.transport === "gateway"
        ? key === "node-lifecycle-result"
          ? state.lifecycleResult
          : key === "network-result"
          ? { network_proven: true, retained: true, public_key: "admitted-key" }
          : key === "footprint-result"
          ? state.footprintResult
          : key === "access-remove"
          ? { access_removed: true }
          : { prepared: true }
        : body.name === "wireguard.prepare-peer-key" ||
            body.name === "wireguard.publish-peer"
        ? { public_key: "admitted-key" }
        : body.name === "agent.status"
        ? { architecture: "x86_64" }
        : body.name === "firewall.rules" || body.name === "firewall.router-lan"
        ? { matches: true }
        : body.name === "node.install-private-dns-listener"
        ? { installed: true, changed: true }
        : body.name === "agent.install"
        ? { installed: true, changed: false, architecture: "x86_64" }
        : body.name === "php.install-build"
        ? { outcome: "applied" }
        : { changed: false };
    }
    data = state.receipts[key];
    if (state.loseRunReplyKey === key) {
      state.loseRunReplyKey = null;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost Node reply");
    }
  } else if (path.endsWith("/wait")) {
    if (body.worker !== state.worker) throw new Error("Wrong parent worker");
    state.operation.status = "waiting";
    state.waiting_for = body.keys;
    data = { ...state.operation, worker: null, lease_seconds: 0 };
  } else if (path.includes("/receipts/")) {
    data = {
      effect_key: `op-7.${decodeURIComponent(path.split("/").at(-1)!)}`,
      exit_code: null,
      finished_at: null,
      state: state.receipt ? "succeeded" : "unknown",
      result: state.receipt,
    };
  } else if (path.endsWith("/run")) {
    state.keys.push(body.key);
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
    const step = state.operation.steps.find((s: { name: string }) =>
      s.name === body.name
    );
    if (step) step.status = body.status;
    else state.operation.steps.push({ name: body.name, status: body.status });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
  } else if (path.endsWith("/complete")) {
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
