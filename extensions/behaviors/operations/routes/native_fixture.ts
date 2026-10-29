/** Fake Gateway confined to a disposable native Swamp repository. */
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-routes.json"));
  const body = raw as Record<string, unknown>;
  const dns = state.operation.kind === "route.private_dns_publish";
  const spec = dns
    ? {
      key: "private-dns-publish",
      transport: "route-dns",
      name: "route.publish-dns",
      prepare: "route.private-dns-prepare",
      input: {
        records: "host-record=proof.test,10.44.0.3\n",
        catalog: '{"requesters":{}}\n',
      },
    }
    : {
      key: "caddy-publish",
      transport: "route-caddy",
      name: "caddy.publish",
      prepare: "route.caddy-prepare",
      input: {
        content: "# private dev proof\n",
        version: "a".repeat(32),
        listen_addresses: ["10.44.0.3"],
        unserved: false,
      },
    };
  let data: unknown;
  const save = () =>
    Deno.writeTextFile("native-routes.json", JSON.stringify(state));
  if (path.endsWith("/claimable")) {
    if (method !== "GET" || raw !== undefined) {
      throw new Error("Discovery must be a read");
    }
    return {
      status: 200,
      body: {
        data: {
          kinds: [{
            kind: state.operation.kind,
            claimable: state.operation.status === "queued" ? 1 : 0,
            oldest_at: "2026-10-29T00:00:00Z",
          }],
        },
      },
    };
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
  if (method === "GET") {
    const key = decodeURIComponent(path.split("/").at(-1)!);
    state.receiptReads++;
    data = {
      effect_key: `op-${state.operation.id}.${key}`,
      state: state.receipts[key] ? "succeeded" : "unknown",
      exit_code: 0,
      result: state.receipts[key] ?? null,
      finished_at: "2026-10-28T21:00:00Z",
    };
    await save();
    return { status: 200, body: { data } };
  }
  if (path.endsWith("/claim")) {
    if (
      body.kind !== state.operation.kind ||
      ["succeeded", "failed", "cancelled"].includes(state.operation.status)
    ) return { status: 200, body: { data: null } };
    state.operation.worker = body.worker;
    state.operation.status = "running";
    data = state.operation;
  } else if (body.worker !== state.operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  if (path.endsWith("/heartbeat")) {
    data = {
      ...state.operation,
      execution_context: {
        descriptors: [
          {
            key: "prepare",
            step: "prepare",
            transport: "gateway",
            name: spec.prepare,
            input: state.operation.input,
            resumable: true,
            required: true,
          },
          ...(state.prepared
            ? [{
              key: spec.key,
              step: spec.key,
              transport: spec.transport,
              name: spec.name,
              input: spec.input,
              resumable: true,
              required: true,
            }]
            : []),
        ],
      },
    };
  }
  if (path.endsWith("/step")) {
    const steps = state.operation.steps as { name: string; status: string }[];
    const step = steps.find((s) => s.name === body.name);
    if (step) step.status = String(body.status);
    else steps.push({ name: String(body.name), status: String(body.status) });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
    data = state.operation;
  }
  if (path.endsWith("/run")) {
    if ("node_id" in body || "effect_key" in body) {
      throw new Error("Caller supplied a transport target or effect key");
    }
    if (body.key === "prepare") {
      if (
        body.name !== spec.prepare ||
        JSON.stringify(body.input) !== JSON.stringify(state.operation.input)
      ) throw new Error("Unadmitted preparation");
      state.prepared = true;
      data = { prepared: true, version: "a".repeat(32) };
    } else {
      if (
        body.key !== spec.key || body.transport !== spec.transport ||
        body.name !== spec.name ||
        JSON.stringify(body.input) !== JSON.stringify(spec.input)
      ) throw new Error("Publication differs from the Gateway render");
      state.invocations.push(body);
      if (!state.receipts[spec.key]) {
        state.runs++;
        state.receipts[spec.key] = dns
          ? {
            outcome: "published",
            data: { ready: true, digest: "b".repeat(64) },
            ready: true,
          }
          : { result: "published", ready: true };
      }
      data = state.receipts[spec.key];
      if (state.loseRunReply) {
        state.loseRunReply = false;
        await save();
        throw new Error("Lost publication reply after Node mutation");
      }
    }
  }
  if (path.endsWith("/complete")) {
    if (
      !state.operation.step_results[spec.key] ||
      JSON.stringify(body.result) !== JSON.stringify(state.receipts[spec.key])
    ) throw new Error("Unproven completion");
    state.operation.status = body.status;
    if (!state.completions.length) state.completions.push(body);
    if (state.loseCompleteReply) {
      state.loseCompleteReply = false;
      await save();
      throw new Error("Lost completion reply");
    }
    data = state.operation;
  }
  await save();
  return { status: 200, body: { data } };
}
